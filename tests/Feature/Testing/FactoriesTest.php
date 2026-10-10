<?php

use Pr4w\SocialTokens\Actions\StoreLinkedInOrganizations;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Exceptions\NeedsReconnectException;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Tests\Fixtures\Owner;

it('makes an active account posting with a credential of its own, as a connection stores them', function () {
    $account = SocialAccount::factory()->create();
    $credential = $account->credential;

    expect($account->status)->toBe(AccountStatus::Active)
        ->and($account->isUsable())->toBeTrue()
        ->and($account->provider)->toBe('google')
        ->and($account->provider_holder_id)->toBe($account->provider_user_id)
        ->and($credential)->not->toBeNull()
        ->and($credential->provider)->toBe('google')
        ->and($credential->provider_holder_id)->toBe($account->provider_user_id)
        ->and($credential->purpose)->toBe(SocialToken::DEFAULT_PURPOSE)
        ->and($credential->access_token)->toStartWith('access-')
        ->and($credential->refresh_token)->toStartWith('refresh-')
        // Scheduled as the Google connector schedules a connection: 25 minutes ahead.
        ->and($credential->renew_at->equalTo($credential->expires_at->copy()->subMinutes(25)))->toBeTrue()
        ->and(SocialAccount::usable()->count())->toBe(1)
        ->and(app(SocialTokens::class)->validAccessTokenFor($account))->toBe($credential->access_token);
});

it('gives the account and its credential the same owner', function () {
    $owner = Owner::create(['name' => 'acme']);

    $account = SocialAccount::factory()->forProvider('tiktok')->ownedBy($owner)->create();

    expect(SocialAccount::ownedBy($owner)->sole()->is($account))->toBeTrue()
        ->and(SocialToken::ownedBy($owner)->sole()->is($account->credential))->toBeTrue()
        ->and($account->credential->provider)->toBe('tiktok')
        ->and($account->ownable->is($owner))->toBeTrue();
});

it('records who connected the account', function () {
    $user = Owner::create(['name' => 'member']);

    expect(SocialAccount::factory()->connectedBy($user)->create()->connectedBy->is($user))->toBeTrue();
});

it('stores an Instagram account on the Meta user credential of its Facebook user', function () {
    $account = SocialAccount::factory()->forProvider('instagram')->heldBy('fb-user-1')->create();

    expect($account->credential->provider)->toBe('facebook')
        ->and($account->credential->provider_holder_id)->toBe('fb-user-1')
        ->and($account->credential->refresh_token)->toBeNull()
        // The Meta connector's 7-day lead time.
        ->and($account->credential->renew_at->equalTo($account->credential->expires_at->copy()->subDays(7)))->toBeTrue();
});

it('lets several Instagram accounts share one credential', function () {
    $credential = SocialToken::factory()->forProvider('facebook', 'fb-user-1')->withoutRefreshToken()->create();

    $accounts = SocialAccount::factory()->count(2)->forProvider('instagram')->heldBy('fb-user-1')
        ->for($credential, 'credential')
        ->create();

    expect($accounts->pluck('social_token_id')->unique()->all())->toBe([$credential->getKey()])
        ->and(SocialToken::count())->toBe(1);
});

it('stores a Facebook Page on a Page token of its own that never expires', function () {
    $page = SocialAccount::factory()->forProvider('facebook')->heldBy('fb-user-1')->create();

    expect($page->credential->provider)->toBe('facebook')
        ->and($page->credential->provider_holder_id)->toBe($page->provider_user_id)
        ->and($page->credential->expires_at)->toBeNull()
        ->and($page->credential->renew_at)->toBeNull()
        ->and($page->provider_holder_id)->toBe('fb-user-1');
});

it('stores a LinkedIn organization on the member\'s organizations credential', function () {
    $organization = SocialAccount::factory()->linkedInOrganization('member-1')->create();

    expect($organization->provider)->toBe('linkedin')
        ->and($organization->provider_holder_id)->toBe('member-1')
        ->and($organization->credential->provider_holder_id)->toBe('member-1')
        ->and($organization->credential->purpose)->toBe(StoreLinkedInOrganizations::PURPOSE);
});

it('puts several organizations of one member on one organizations credential, under an owner', function () {
    // One credential per member and purpose under an owner (the unique key):
    // a second one failed to insert.
    $owner = Owner::create(['name' => 'acme']);

    $organizations = SocialAccount::factory()->count(2)->linkedInOrganization('member-1')->ownedBy($owner)->create();

    expect($organizations->pluck('social_token_id')->unique())->toHaveCount(1)
        ->and(SocialToken::ownedBy($owner)->sole()->purpose)->toBe(StoreLinkedInOrganizations::PURPOSE);
});

it('puts the accounts of one holder on one credential, as a connection stores them', function (?string $ownerName) {
    $owner = $ownerName === null ? null : Owner::create(['name' => $ownerName]);

    $organizations = SocialAccount::factory()->count(2)->linkedInOrganization('member-1')->ownedBy($owner)->create();
    $instagram = SocialAccount::factory()->count(2)->forProvider('instagram')->heldBy('fb-user-1')->ownedBy($owner)->create();

    expect($organizations->pluck('social_token_id')->unique())->toHaveCount(1)
        ->and($instagram->pluck('social_token_id')->unique())->toHaveCount(1)
        ->and(SocialToken::count())->toBe(2);
})->with(['owner-less' => null, 'with an owner' => 'acme']);

it('keeps the credentials of a member\'s profile and organizations, and of each owner, apart', function () {
    $owner = Owner::create(['name' => 'acme']);

    $profile = SocialAccount::factory()->forProvider('linkedin')->ownedBy($owner)->create(['provider_user_id' => 'member-1']);
    $organization = SocialAccount::factory()->linkedInOrganization('member-1')->ownedBy($owner)->create();
    $elsewhere = SocialAccount::factory()->linkedInOrganization('member-1')->create();

    expect(collect([$profile, $organization, $elsewhere])->pluck('social_token_id')->unique())->toHaveCount(3)
        ->and($profile->credential->purpose)->toBe(SocialToken::DEFAULT_PURPOSE)
        ->and($elsewhere->credential->ownable_type)->toBeNull();
});

it('shapes the credential with its own states, which add up', function () {
    $account = SocialAccount::factory()
        ->credential(['scopes' => ['youtube.upload']])
        ->credential(fn ($credential) => $credential->needsReconnect('invalid_grant'))
        ->create();

    expect($account->credential->scopes)->toBe(['youtube.upload'])
        ->and($account->credential->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($account->credential->last_error)->toBe('invalid_grant')
        ->and($account->status)->toBe(AccountStatus::Active)
        ->and($account->effectiveStatus())->toBe(AccountStatus::NeedsReconnect)
        ->and(SocialAccount::unusable()->count())->toBe(1);

    expect(fn () => app(SocialTokens::class)->validAccessTokenFor($account))
        ->toThrow(fn (NeedsReconnectException $e) => expect($e->transient)->toBeFalse());
});

it('leaves a credential named through for() alone', function () {
    $credential = SocialToken::factory()->create();

    $account = SocialAccount::factory()
        ->for($credential, 'credential')
        ->credential(fn ($factory) => $factory->revoked())
        ->create();

    expect($account->social_token_id)->toBe($credential->getKey())
        ->and($credential->fresh()->status)->toBe(AccountStatus::Active);
});

it('makes a credential due for renewal whose token still works', function () {
    $credential = SocialToken::factory()->dueForRenewal()->create();
    $account = SocialAccount::factory()->for($credential, 'credential')->create();

    expect(SocialToken::dueForRenewal()->sole()->is($credential))->toBeTrue()
        ->and(app(SocialTokens::class)->validAccessTokenFor($account))->toBe($credential->access_token);
});

it('makes an expired credential, scheduled in the past', function () {
    $credential = SocialToken::factory()->forProvider('tiktok')->expired()->create();

    expect($credential->isAccessTokenExpired())->toBeTrue()
        ->and($credential->isDueForRenewal())->toBeTrue();
});

it('makes revoked credentials without tokens, as revoke() leaves them', function () {
    $credential = SocialToken::factory()->revoked()->create();

    expect($credential->status)->toBe(AccountStatus::Revoked)
        ->and($credential->access_token)->toBeNull()
        ->and($credential->refresh_token)->toBeNull()
        ->and($credential->renew_at)->toBeNull();
});

it('flags or revokes an account on its own', function () {
    $flagged = SocialAccount::factory()->needsReconnect('Page no longer managed by the connected Facebook user.')->create();
    $revoked = SocialAccount::factory()->revoked()->create();

    expect($flagged->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($flagged->last_error)->toBe('Page no longer managed by the connected Facebook user.')
        ->and($flagged->credential->status)->toBe(AccountStatus::Active)
        ->and($revoked->status)->toBe(AccountStatus::Revoked)
        ->and($revoked->wasDisconnected())->toBeFalse();
});

it('makes a disconnected account, revoked with its credential', function () {
    $account = SocialAccount::factory()->disconnected()->create();

    expect($account->wasDisconnected())->toBeTrue()
        ->and($account->credential->status)->toBe(AccountStatus::Revoked)
        ->and($account->credential->access_token)->toBeNull();
});

it('leaves renew_at null for a provider without a connector', function () {
    expect(SocialToken::factory()->forProvider('acme')->create()->renew_at)->toBeNull();
});
