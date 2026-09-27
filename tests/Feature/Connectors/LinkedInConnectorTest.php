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
        ->toBe(RenewalStrategy::ReauthOnly);
});

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
    Http::fake(['linkedin.com/oauth/v2/accessToken' => Http::response(['error' => 'server_error'], 400)]);

    $result = linkedin()->refreshCredential(linkedinCredential());

    expect($result->outcome)->toBe(RenewalOutcome::Transient)->and($result->unknown)->toBeTrue();
});

it('lists approved organizations the member administers', function () {
    Http::fake(['api.linkedin.com/v2/organizationAcls*' => Http::response(['elements' => [
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
    ]])]);

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

it('returns a failure result when the org listing errors', function () {
    Http::fake(['api.linkedin.com/v2/organizationAcls*' => Http::response(['message' => 'forbidden'], 403)]);

    expect(linkedin()->fetchOrganizations('member-token'))->toBeInstanceOf(RenewalResult::class);
});

it('pages through organizations until the total is reached', function () {
    Http::fake(function ($request) {
        $start = (int) ($request['start'] ?? 0);

        return Http::response([
            'paging' => ['start' => $start, 'count' => 100, 'total' => 2],
            'elements' => [[
                'role' => 'ADMINISTRATOR',
                'state' => 'APPROVED',
                'organization~' => ['id' => $start + 1, 'localizedName' => 'Org '.($start + 1)],
            ]],
        ]);
    });

    expect(collect(linkedin()->fetchOrganizations('member-token'))->pluck('id')->all())->toBe(['1', '2']);

    Http::assertSentCount(2);
});

it('fails instead of returning a partial organization list when a later page errors', function () {
    Http::fake(fn ($request) => (int) ($request['start'] ?? 0) > 0
        ? Http::response(['message' => 'boom'], 500)
        : Http::response([
            'paging' => ['start' => 0, 'count' => 100, 'total' => 2],
            'elements' => [['role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'organization~' => ['id' => 1]]],
        ]));

    expect(linkedin()->fetchOrganizations('member-token'))->toBeInstanceOf(RenewalResult::class);
});
