<?php

/*
 * Regression: LinkedIn organizations must be filtered by posting role.
 *
 * organizationAcls returns one element per (organization, role). Only some
 * roles can publish as the Page with w_organization_social. In v1.1.0,
 * LinkedInConnector::mapOrganizations() keeps every APPROVED element whatever
 * its role, so ANALYST / CURATOR / LEAD_GEN_FORMS_MANAGER grants become Active
 * "postable" rows, and an organization listed under two roles comes back twice.
 *
 * Desired: only posting roles (configurable via
 * social-tokens.connectors.linkedin.posting_roles, default ADMINISTRATOR,
 * CONTENT_ADMINISTRATOR, DIRECT_SPONSORED_CONTENT_POSTER, RECRUITING_POSTER)
 * yield organizations, and each organization id appears once.
 */

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Actions\StoreLinkedInOrganizations;
use Pr4w\SocialTokens\Connectors\LinkedInConnector;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\AccountConnected;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

/**
 * The posting roles the connector must use when none are configured.
 */
function defaultLinkedInPostingRoles(): array
{
    return ['ADMINISTRATOR', 'CONTENT_ADMINISTRATOR', 'DIRECT_SPONSORED_CONTENT_POSTER', 'RECRUITING_POSTER'];
}

/**
 * One raw organizationAcls element, as LinkedIn returns it with the
 * roleAssignee projection used by LinkedInConnector::fetchOrganizations().
 */
function postingRoleAcl(string $orgId, string $role, string $state = 'APPROVED'): array
{
    return [
        'role' => $role,
        'state' => $state,
        'organization~' => ['id' => $orgId, 'localizedName' => "Org {$orgId}"],
    ];
}

function fakePostingRoleAcls(array $elements): void
{
    Http::fake(['api.linkedin.com/v2/organizationAcls*' => Http::response(['elements' => $elements])]);
}

/*
|--------------------------------------------------------------------------
| Connector: LinkedInConnector::fetchOrganizations()
|--------------------------------------------------------------------------
*/

it('ships the default posting roles in the package config', function () {
    expect(config('social-tokens.connectors.linkedin.posting_roles'))
        ->toEqualCanonicalizing(defaultLinkedInPostingRoles());
});

it('lists only organizations the member holds a posting role on', function () {
    fakePostingRoleAcls([
        postingRoleAcl('1', 'ADMINISTRATOR'),
        postingRoleAcl('2', 'CONTENT_ADMINISTRATOR'),
        postingRoleAcl('3', 'DIRECT_SPONSORED_CONTENT_POSTER'),
        postingRoleAcl('4', 'RECRUITING_POSTER'),
        postingRoleAcl('5', 'ANALYST'),
        postingRoleAcl('6', 'CURATOR'),
        postingRoleAcl('7', 'LEAD_GEN_FORMS_MANAGER'),
    ]);

    $orgs = app(ConnectorRegistry::class)->for('linkedin')->fetchOrganizations('member-token');

    expect(collect($orgs)->pluck('id')->all())->toEqualCanonicalizing(['1', '2', '3', '4']);
});

it('falls back to the default posting roles when the connector config has no posting_roles key', function () {
    // An app that published the v1.1.0 config has a "linkedin" block without
    // the new key (mergeConfigFrom does not deep-merge "connectors").
    fakePostingRoleAcls([
        postingRoleAcl('1', 'ADMINISTRATOR'),
        postingRoleAcl('2', 'ANALYST'),
        postingRoleAcl('3', 'RECRUITING_POSTER'),
        postingRoleAcl('4', 'CURATOR'),
    ]);

    $orgs = (new LinkedInConnector(['refresh_enabled' => false], 'linkedin'))->fetchOrganizations('member-token');

    expect(collect($orgs)->pluck('id')->all())->toEqualCanonicalizing(['1', '3']);
});

it('honours a custom posting_roles list from the connector config', function () {
    fakePostingRoleAcls([
        postingRoleAcl('1', 'ADMINISTRATOR'),
        postingRoleAcl('2', 'CONTENT_ADMINISTRATOR'),
        postingRoleAcl('3', 'ANALYST'),
        postingRoleAcl('4', 'CURATOR'),
    ]);

    $connector = new LinkedInConnector(['posting_roles' => ['ADMINISTRATOR', 'ANALYST']], 'linkedin');

    expect(collect($connector->fetchOrganizations('member-token'))->pluck('id')->all())->toEqualCanonicalizing(['1', '3']);
});

it('returns an organization listed under a posting and a non-posting role once, with the posting role', function (array $elements) {
    fakePostingRoleAcls($elements);

    $orgs = app(ConnectorRegistry::class)->for('linkedin')->fetchOrganizations('member-token');

    expect($orgs)->toHaveCount(1)
        ->and($orgs[0]['id'])->toBe('20')
        ->and($orgs[0]['role'])->toBe('ADMINISTRATOR');
})->with([
    'posting role first' => [[postingRoleAcl('20', 'ADMINISTRATOR'), postingRoleAcl('20', 'ANALYST')]],
    'posting role last' => [[postingRoleAcl('20', 'ANALYST'), postingRoleAcl('20', 'ADMINISTRATOR')]],
]);

it('returns an organization listed under two posting roles once', function () {
    fakePostingRoleAcls([
        postingRoleAcl('21', 'ADMINISTRATOR'),
        postingRoleAcl('21', 'DIRECT_SPONSORED_CONTENT_POSTER'),
        postingRoleAcl('22', 'RECRUITING_POSTER'),
    ]);

    $orgs = collect(app(ConnectorRegistry::class)->for('linkedin')->fetchOrganizations('member-token'));

    // Which of the two posting roles is kept is up to the implementation.
    expect($orgs->pluck('id')->all())->toEqualCanonicalizing(['21', '22'])
        ->and($orgs->firstWhere('id', '21')['role'])->toBeIn(defaultLinkedInPostingRoles());
});

it('deduplicates an organization whose roles come back on different result pages', function () {
    Http::fake(fn ($request) => Http::response((int) ($request['start'] ?? 0) === 0
        ? ['paging' => ['start' => 0, 'count' => 100, 'total' => 2], 'elements' => [postingRoleAcl('40', 'ANALYST')]]
        : ['paging' => ['start' => 1, 'count' => 100, 'total' => 2], 'elements' => [postingRoleAcl('40', 'ADMINISTRATOR')]]));

    $orgs = app(ConnectorRegistry::class)->for('linkedin')->fetchOrganizations('member-token');

    expect($orgs)->toHaveCount(1)
        ->and($orgs[0]['role'])->toBe('ADMINISTRATOR');
});

/*
|--------------------------------------------------------------------------
| Action: StoreLinkedInOrganizations::handle()
|--------------------------------------------------------------------------
*/

it('stores an active account for each posting role', function () {
    // Guard: the filter must not drop any of the four posting roles.
    fakePostingRoleAcls([
        postingRoleAcl('1', 'ADMINISTRATOR'),
        postingRoleAcl('2', 'CONTENT_ADMINISTRATOR'),
        postingRoleAcl('3', 'DIRECT_SPONSORED_CONTENT_POSTER'),
        postingRoleAcl('4', 'RECRUITING_POSTER'),
    ]);

    $accounts = app(StoreLinkedInOrganizations::class)->handle(accessToken: 'member-token', memberId: 'member-1');

    expect($accounts)->toHaveCount(4)
        ->and(SocialAccount::where('status', AccountStatus::Active->value)->pluck('provider_user_id')->sort()->values()->all())
        ->toBe(['1', '2', '3', '4']);
});

it('does not store organizations held only through a non-posting role', function () {
    Event::fake([AccountConnected::class]);
    fakePostingRoleAcls([
        postingRoleAcl('1', 'ADMINISTRATOR'),
        postingRoleAcl('2', 'ANALYST'),
        postingRoleAcl('3', 'CURATOR'),
        postingRoleAcl('4', 'LEAD_GEN_FORMS_MANAGER'),
    ]);

    $accounts = app(StoreLinkedInOrganizations::class)->handle(accessToken: 'member-token', memberId: 'member-1');

    expect($accounts->pluck('provider_user_id')->all())->toBe(['1'])
        ->and(SocialAccount::where('provider', 'linkedin')->pluck('provider_user_id')->all())->toBe(['1']);

    Event::assertDispatchedTimes(AccountConnected::class, 1);
});

it('flags an organization for reconnect when the member is demoted to a non-posting role', function () {
    Http::fake(['api.linkedin.com/v2/organizationAcls*' => Http::sequence()
        ->push(['elements' => [postingRoleAcl('10', 'ADMINISTRATOR'), postingRoleAcl('11', 'ADMINISTRATOR')]])
        ->push(['elements' => [postingRoleAcl('10', 'ANALYST'), postingRoleAcl('11', 'ADMINISTRATOR')]])]);

    $store = app(StoreLinkedInOrganizations::class);
    $store->handle(accessToken: 'member-token', memberId: 'member-2');
    $store->handle(accessToken: 'member-token-2', memberId: 'member-2');

    // Org 10 is still listed, but only through ANALYST: it can no longer post.
    expect(SocialAccount::where('provider_user_id', '10')->first()->status)->toBe(AccountStatus::NeedsReconnect)
        ->and(SocialAccount::where('provider_user_id', '11')->first()->status)->toBe(AccountStatus::Active);
});

it('returns one account and fires AccountConnected once for an organization listed under two roles', function () {
    Event::fake([AccountConnected::class]);
    fakePostingRoleAcls([
        postingRoleAcl('30', 'ADMINISTRATOR'),
        postingRoleAcl('30', 'ANALYST'),
    ]);

    $accounts = app(StoreLinkedInOrganizations::class)->handle(accessToken: 'member-token', memberId: 'member-3');

    expect($accounts)->toHaveCount(1)
        ->and(SocialAccount::where('provider_user_id', '30')->count())->toBe(1)
        ->and(SocialAccount::where('provider_user_id', '30')->first()->profile['role'])->toBe('ADMINISTRATOR');

    Event::assertDispatchedTimes(AccountConnected::class, 1);
});

it('reads posting_roles from the social-tokens.connectors.linkedin config', function () {
    config()->set('social-tokens.connectors.linkedin.posting_roles', ['ADMINISTRATOR']);
    app()->forgetInstance(ConnectorRegistry::class);

    fakePostingRoleAcls([
        postingRoleAcl('1', 'ADMINISTRATOR'),
        postingRoleAcl('2', 'RECRUITING_POSTER'),
    ]);

    $accounts = app(StoreLinkedInOrganizations::class)->handle(accessToken: 'member-token', memberId: 'member-4');

    expect($accounts->pluck('provider_user_id')->all())->toBe(['1']);
});
