<?php

use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Actions\StoreLinkedInOrganizations;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Tests\Fixtures\Owner;

function fakeOrganizations(array $elements): void
{
    Http::fake(['api.linkedin.com/v2/organizationAcls*' => Http::response(['elements' => $elements])]);
}

function orgElement(string $id, string $name): array
{
    return [
        'role' => 'ADMINISTRATOR',
        'state' => 'APPROVED',
        'organization~' => ['id' => $id, 'localizedName' => $name],
    ];
}

beforeEach(fn () => $this->store = app(StoreLinkedInOrganizations::class));

it('creates one account per organization on a shared member credential', function () {
    fakeOrganizations([orgElement('111', 'Acme'), orgElement('222', 'Globex')]);
    $owner = Owner::create();

    $accounts = $this->store->handle(
        accessToken: 'member-token',
        memberId: 'member-1',
        owner: $owner,
        expiresAt: now()->addDays(60),
    );

    expect($accounts)->toHaveCount(2);

    $org = SocialAccount::where('provider_user_id', '111')->first();
    expect($org->provider)->toBe('linkedin')
        ->and($org->provider_holder_id)->toBe('member-1')
        ->and($org->name)->toBe('Acme')
        ->and($org->profile['organization_urn'])->toBe('urn:li:organization:111')
        ->and($org->ownable->is($owner))->toBeTrue();

    // Both organizations post with one shared, renewable member credential.
    $credential = $org->credential;
    expect($credential->provider)->toBe('linkedin')
        ->and($credential->provider_holder_id)->toBe('member-1')
        ->and($credential->access_token)->toBe('member-token')
        ->and($credential->renew_at)->not->toBeNull()
        ->and(SocialAccount::where('provider', 'linkedin')->pluck('social_token_id')->unique())->toHaveCount(1);
});

it('reconciles organizations the member no longer administers', function () {
    SocialAccount::create([
        'provider' => 'linkedin', 'provider_user_id' => 'dropped-org',
        'provider_holder_id' => 'member-1', 'status' => AccountStatus::Active,
    ]);
    // The member's personal row (holder id null) must never be touched.
    $personal = SocialAccount::create([
        'provider' => 'linkedin', 'provider_user_id' => 'member-1',
        'provider_holder_id' => null, 'status' => AccountStatus::Active,
    ]);

    fakeOrganizations([orgElement('111', 'Acme')]);
    $this->store->handle(accessToken: 'member-token', memberId: 'member-1');

    expect(SocialAccount::where('provider_user_id', 'dropped-org')->first()->status)
        ->toBe(AccountStatus::NeedsReconnect)
        ->and($personal->fresh()->status)->toBe(AccountStatus::Active);
});

it('throws when the organization listing fails', function () {
    Http::fake(['api.linkedin.com/v2/organizationAcls*' => Http::response(['message' => 'forbidden'], 403)]);

    $this->store->handle(accessToken: 'member-token', memberId: 'member-1');
})->throws(RuntimeException::class);
