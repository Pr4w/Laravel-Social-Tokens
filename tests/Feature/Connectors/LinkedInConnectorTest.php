<?php

use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Connectors\LinkedInConnector;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Pr4w\SocialTokens\Support\RenewalResult;

function linkedin(): LinkedInConnector
{
    return app(ConnectorRegistry::class)->for('linkedin');
}

function linkedinCredential(array $attrs = []): SocialToken
{
    return SocialToken::create(array_merge([
        'provider' => 'linkedin',
        'provider_holder_id' => 'member-'.uniqid(),
        'refresh_token' => 'refresh-1',
        'status' => AccountStatus::Active,
    ], $attrs));
}

it('derives its strategy from config', function () {
    expect((new LinkedInConnector(['refresh_enabled' => true]))->renewalStrategy())
        ->toBe(RenewalStrategy::StableRefreshToken)
        ->and((new LinkedInConnector(['refresh_enabled' => false]))->renewalStrategy())
        ->toBe(RenewalStrategy::ReauthOnly)
        ->and((new LinkedInConnector)->renewalStrategy())
        ->toBe(RenewalStrategy::StableRefreshToken)
        ->and((require __DIR__.'/../../../config/social-tokens.php')['connectors']['linkedin']['refresh_enabled'])
        ->toBeTrue();
});

it('reads refresh_enabled as every other boolean setting, so a string that says no turns refresh off', function (mixed $value, RenewalStrategy $strategy) {
    // env() returns LINKEDIN_REFRESH_ENABLED=off as the string 'off', which
    // plain truthiness took for true.
    expect((new LinkedInConnector(['refresh_enabled' => $value], 'linkedin'))->renewalStrategy())->toBe($strategy);
})->with([
    'off' => ['off', RenewalStrategy::ReauthOnly],
    'no' => ['no', RenewalStrategy::ReauthOnly],
    'false' => ['false', RenewalStrategy::ReauthOnly],
    '0' => ['0', RenewalStrategy::ReauthOnly],
    'null, the default' => [null, RenewalStrategy::StableRefreshToken],
    'on' => ['on', RenewalStrategy::StableRefreshToken],
    'true' => ['true', RenewalStrategy::StableRefreshToken],
    '1' => ['1', RenewalStrategy::StableRefreshToken],
]);

it('renews via the refresh token grant', function () {
    Http::fake(['linkedin.com/oauth/v2/accessToken' => Http::response([
        'access_token' => 'new-access',
        'expires_in' => 5184000,
        'refresh_token' => 'new-refresh',
        'refresh_token_expires_in' => 31536000,
    ])]);

    $result = linkedin()->refreshCredential(linkedinCredential());

    expect($result->succeeded())->toBeTrue()
        ->and($result->accessToken)->toBe('new-access')
        ->and($result->refreshToken)->toBe('new-refresh')
        ->and($result->refreshExpiresAt)->not->toBeNull();

    Http::assertSent(fn ($request) => $request['grant_type'] === 'refresh_token'
        && $request['client_id'] === 'linkedin-id'
        && $request['refresh_token'] === 'refresh-1');
});

it('passes the refreshed scopes along', function () {
    Http::fake(['linkedin.com/oauth/v2/accessToken' => Http::response([
        'access_token' => 'new-access', 'expires_in' => 5184000, 'scope' => 'r_basicprofile,w_organization_social',
    ])]);

    expect(linkedin()->refreshCredential(linkedinCredential())->profile)->toBe(['scope' => 'r_basicprofile,w_organization_social']);
});

it('is terminal without a refresh token', function () {
    Http::fake();

    expect(linkedin()->refreshCredential(linkedinCredential(['refresh_token' => null]))->outcome)->toBe(RenewalOutcome::Terminal);
    Http::assertNothingSent();
});

it('maps invalid_grant to terminal', function () {
    Http::fake(['linkedin.com/oauth/v2/accessToken' => Http::response(['error' => 'invalid_grant'], 400)]);

    expect(linkedin()->refreshCredential(linkedinCredential())->outcome)->toBe(RenewalOutcome::Terminal);
});

it('maps a refresh token issued to another LinkedIn app to terminal', function () {
    // Seen in production after an app switched LinkedIn apps: the old refresh
    // token can never be redeemed by the new client, retrying is pointless.
    Http::fake(['linkedin.com/oauth/v2/accessToken' => Http::response([
        'error' => 'refresh_token_client_mismatch',
        'error_description' => 'The passed in client_id does not own the refresh token',
    ], 400)]);

    $result = linkedin()->refreshCredential(linkedinCredential());

    expect($result->outcome)->toBe(RenewalOutcome::Terminal)
        ->and($result->reason)->toContain('refresh_token_client_mismatch');
});

it('maps an unknown oauth error to transient', function () {
    Http::fake(['linkedin.com/oauth/v2/accessToken' => Http::response(['error' => 'something_new'], 400)]);

    $result = linkedin()->refreshCredential(linkedinCredential());

    expect($result->outcome)->toBe(RenewalOutcome::Transient)->and($result->unknown)->toBeTrue();
});

it('lists approved organizations the member administers', function () {
    Http::fake(linkedInRest(Http::response(['elements' => [
        [
            'role' => 'ADMINISTRATOR',
            'state' => 'APPROVED',
            'organization~' => [
                'id' => 123,
                'localizedName' => 'Acme',
                'logoV2' => ['original~' => ['elements' => [['identifiers' => [['identifier' => 'logo-url']]]]]],
            ],
        ],
        [
            'role' => 'ADMINISTRATOR',
            'state' => 'REQUESTED', // pending, must be excluded
            'organization~' => ['id' => 456, 'localizedName' => 'Pending Co'],
        ],
    ]])));

    $orgs = linkedin()->fetchOrganizations('member-token');

    expect($orgs)->toHaveCount(1)
        ->and($orgs[0])->toBe([
            'id' => '123',
            'urn' => 'urn:li:organization:123',
            'name' => 'Acme',
            'logo' => 'logo-url',
            'role' => 'ADMINISTRATOR',
        ]);

    Http::assertSent(fn ($request) => $request->hasHeader('X-Restli-Protocol-Version', '2.0.0')
        && str_contains($request->url(), 'q=roleAssignee'));
});

/*
 * LinkedIn sunset its unversioned /v2 Marketing APIs in June 2023, and its
 * /rest/organizationAcls refuses projections (400 "projection parameter is
 * not allowed for this endpoint"). The grants come undecorated; the names in
 * one batch from /rest/organizations, and the logos' URLs in one batch from
 * /rest/images, which take urn:li:image URNs. Rest.li 2.0: List( , ) left
 * unencoded, the URNs inside encoded. Shapes as LinkedIn served them in
 * production with LinkedIn-Version 202605.
 */
it('lists the organizations from LinkedIn\'s versioned API', function () {
    config(['social-tokens.connectors.linkedin.api_version' => '202611']);
    Http::fake([
        'api.linkedin.com/rest/organizationAcls*' => fn ($request) => isset($request['projection'])
            ? Http::response(['status' => 400, 'message' => 'projection parameter is not allowed for this endpoint'], 400)
            : Http::response(['paging' => ['start' => 0, 'count' => 100, 'links' => []], 'elements' => [
                ['role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'organization' => 'urn:li:organization:123', 'roleAssignee' => 'urn:li:person:abc'],
                ['role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'organization' => 'urn:li:organization:456', 'roleAssignee' => 'urn:li:person:abc'],
            ]]),
        'api.linkedin.com/rest/organizations*' => Http::response([
            'results' => [
                '123' => ['id' => 123, 'localizedName' => 'Acme', 'logoV2' => ['original' => 'urn:li:digitalmediaAsset:C4D0BAQE6V6rj_w1yVQ']],
                '456' => ['id' => 456, 'localizedName' => 'Globex'], // no logo
            ],
            'statuses' => ['123' => 200, '456' => 200],
            'errors' => [],
        ]),
        'api.linkedin.com/rest/images*' => Http::response(['results' => [
            'urn:li:image:C4D0BAQE6V6rj_w1yVQ' => [
                'id' => 'urn:li:image:C4D0BAQE6V6rj_w1yVQ',
                'status' => 'AVAILABLE',
                'downloadUrl' => 'https://media.licdn.com/dms/image/acme.png?e=1',
                'downloadUrlExpiresAt' => 1767225600000,
            ],
        ], 'statuses' => [], 'errors' => []]),
    ]);

    expect(linkedin()->fetchOrganizations('member-token'))->toBe([
        ['id' => '123', 'urn' => 'urn:li:organization:123', 'name' => 'Acme', 'logo' => 'https://media.licdn.com/dms/image/acme.png?e=1', 'role' => 'ADMINISTRATOR'],
        ['id' => '456', 'urn' => 'urn:li:organization:456', 'name' => 'Globex', 'logo' => null, 'role' => 'ADMINISTRATOR'],
    ]);

    Http::assertSentCount(3);
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.linkedin.com/rest/organizationAcls?')
        && $request['q'] === 'roleAssignee'
        && ! isset($request['projection']));
    Http::assertSent(fn ($request) => $request->url() === 'https://api.linkedin.com/rest/organizations?ids=List(123,456)');
    Http::assertSent(fn ($request) => $request->url() === 'https://api.linkedin.com/rest/images?ids=List(urn%3Ali%3Aimage%3AC4D0BAQE6V6rj_w1yVQ)');

    expect(collect(Http::recorded())->every(fn (array $sent) => $sent[0]->hasHeader('LinkedIn-Version', '202611')
        && $sent[0]->hasHeader('X-Restli-Protocol-Version', '2.0.0')
        && $sent[0]->hasHeader('Authorization', 'Bearer member-token')))->toBeTrue();
});

it('returns a failure result when the org listing errors', function () {
    Http::fake(linkedInRest(Http::response(['message' => 'forbidden'], 403)));

    expect(linkedin()->fetchOrganizations('member-token'))->toBeInstanceOf(RenewalResult::class);
});

it('pages through organizations until the total is reached', function () {
    Http::fake(linkedInRest(function ($request) {
        $start = (int) ($request['start'] ?? 0);

        return Http::response([
            'paging' => ['start' => $start, 'count' => 100, 'total' => 2],
            'elements' => [[
                'role' => 'ADMINISTRATOR',
                'state' => 'APPROVED',
                'organization~' => ['id' => $start + 1, 'localizedName' => 'Org '.($start + 1)],
            ]],
        ]);
    }));

    expect(collect(linkedin()->fetchOrganizations('member-token'))->pluck('id')->all())->toBe(['1', '2']);

    Http::assertSentCount(3); // two result pages, then both names in one batch
});

it('fails instead of returning a partial organization list when a later page errors', function () {
    Http::fake(linkedInRest(fn ($request) => (int) ($request['start'] ?? 0) > 0
        ? Http::response(['message' => 'boom'], 500)
        : Http::response([
            'paging' => ['start' => 0, 'count' => 100, 'total' => 2],
            'elements' => [['role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'organization~' => ['id' => 1]]],
        ])));

    expect(linkedin()->fetchOrganizations('member-token'))->toBeInstanceOf(RenewalResult::class);
});
