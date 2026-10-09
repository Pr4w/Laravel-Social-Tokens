<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Actions\StoreAccountFromSocialite;
use Pr4w\SocialTokens\Actions\StoreLinkedInOrganizations;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\CredentialExpiringSoon;
use Pr4w\SocialTokens\Exceptions\NeedsReconnectException;
use Pr4w\SocialTokens\Jobs\RenewCredential;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
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
    // The member's personal row (stored by the real action, so it carries the
    // same holder id as the organizations) must never be touched.
    $personal = app(StoreAccountFromSocialite::class)->handle('linkedin', socialiteUser(['id' => 'member-1']));

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

it('keeps organizations from a later result page', function () {
    SocialAccount::create([
        'provider' => 'linkedin', 'provider_user_id' => '222',
        'provider_holder_id' => 'member-1', 'status' => AccountStatus::Active,
    ]);

    Http::fake(fn ($request) => Http::response((int) ($request['start'] ?? 0) === 0
        ? ['paging' => ['start' => 0, 'count' => 100, 'total' => 2], 'elements' => [orgElement('111', 'Acme')]]
        : ['paging' => ['start' => 1, 'count' => 100, 'total' => 2], 'elements' => [orgElement('222', 'Globex')]]));

    $this->store->handle(accessToken: 'member-token', memberId: 'member-1');

    expect(SocialAccount::where('provider_user_id', '222')->first()->status)->toBe(AccountStatus::Active)
        ->and(SocialAccount::where('provider_holder_id', 'member-1')->count())->toBe(2);
});

// Re-syncing with the stored member token --------------------------------------
//
// Apps re-sync the organization list (to pick up new Pages) with the member
// token already stored, which carries no expiry of its own. The member token's
// expiry was overwritten with whatever the re-sync passed: null made a
// ReauthOnly credential static (never warned, handed out after it died), and
// an invented one moved the warning past the real expiry.

function resyncMemberCredential(): SocialToken
{
    return SocialToken::query()->where('provider', 'linkedin')->where('provider_holder_id', 'member-1')->sole();
}

it('keeps the member token expiry and schedule when re-synced with the stored token', function () {
    fakeOrganizations([orgElement('111', 'Acme')]);
    $this->store->handle(accessToken: 'member-token', memberId: 'member-1', expiresAt: now()->addDays(60));
    $before = resyncMemberCredential();

    $this->travel(30)->days();
    $this->store->handle(accessToken: $before->access_token, memberId: 'member-1');

    $after = resyncMemberCredential();

    expect($after->is($before))->toBeTrue()
        ->and($after->expires_at?->equalTo($before->expires_at))->toBeTrue()
        ->and($after->renew_at?->equalTo($before->renew_at))->toBeTrue()
        ->and($after->last_renewed_at->equalTo($before->last_renewed_at))->toBeTrue();
});

it('does not warn again when re-synced after the expiry warning', function () {
    Event::fake([CredentialExpiringSoon::class]);
    fakeOrganizations([orgElement('111', 'Acme')]);
    $this->store->handle(accessToken: 'member-token', memberId: 'member-1', expiresAt: now()->addDays(60));

    $this->travelTo(resyncMemberCredential()->renew_at->copy()->addMinute());
    (new RenewCredential(resyncMemberCredential()))->handle(app(SocialTokens::class), app(ConnectorRegistry::class));

    $this->store->handle(accessToken: 'member-token', memberId: 'member-1');
    (new RenewCredential(resyncMemberCredential()))->handle(app(SocialTokens::class), app(ConnectorRegistry::class));

    Event::assertDispatchedTimes(CredentialExpiringSoon::class, 1);
    expect(resyncMemberCredential()->renew_at->equalTo(resyncMemberCredential()->expires_at))->toBeTrue();
});

it('still flags the stored token once it expires after a re-sync', function () {
    fakeOrganizations([orgElement('111', 'Acme')]);
    $this->store->handle(accessToken: 'member-token', memberId: 'member-1', expiresAt: now()->addDays(60));
    $this->store->handle(accessToken: 'member-token', memberId: 'member-1');

    $this->travel(61)->days();
    $org = SocialAccount::where('provider_user_id', '111')->sole();

    expect(fn () => app(SocialTokens::class)->validAccessTokenFor($org))->toThrow(NeedsReconnectException::class);
});

it('takes the expiry and the issue date of a new member token', function () {
    fakeOrganizations([orgElement('111', 'Acme')]);
    $this->store->handle(accessToken: 'member-token', memberId: 'member-1', expiresAt: now()->addDays(60));

    $this->travel(30)->days();
    $this->store->handle(accessToken: 'member-token-2', memberId: 'member-1', expiresAt: now()->addDays(60));

    expect(resyncMemberCredential()->expires_at->timestamp)->toBe(now()->addDays(60)->timestamp)
        ->and(resyncMemberCredential()->last_renewed_at->timestamp)->toBe(now()->timestamp);
});
