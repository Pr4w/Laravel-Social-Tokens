<?php

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Pr4w\SocialTokens\Actions\StoreAccountFromSocialite;
use Pr4w\SocialTokens\Actions\StoreLinkedInOrganizations;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Jobs\RenewCredential;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Tests\Fixtures\FakeConnector;
use Pr4w\SocialTokens\Tests\Fixtures\Owner;

/*
 * The member's personal profile (Socialite, OIDC scopes + w_member_social) and
 * the organizations (an app-side exchange with w_organization_social) are two
 * authorizations with two tokens. They were stored on one (linkedin, member,
 * owner) credential, so whichever flow ran last replaced the other's token,
 * refresh token and scopes: every post as the organizations got a 403 after
 * the member connected their profile, while the organization rows still
 * listed w_organization_social and introspection said the token was active.
 */

beforeEach(function () {
    Http::fake(['api.linkedin.com/v2/organizationAcls*' => Http::response(['elements' => [[
        'role' => 'ADMINISTRATOR',
        'state' => 'APPROVED',
        'organization~' => ['id' => '111', 'localizedName' => 'Acme'],
    ]]])]);

    $this->owner = Owner::create();
});

function separateStoreOrganizations(Owner $owner, string $token = 'org-token'): SocialAccount
{
    return app(StoreLinkedInOrganizations::class)->handle(
        accessToken: $token,
        memberId: 'member-1',
        owner: $owner,
        refreshToken: "{$token}-refresh",
        expiresAt: now()->addDays(60),
        scopes: ['r_organization_admin', 'w_organization_social'],
    )->sole();
}

function separateStoreProfile(Owner $owner, string $token = 'profile-token'): SocialAccount
{
    return app(StoreAccountFromSocialite::class)->handle('linkedin', socialiteUser([
        'id' => 'member-1',
        'token' => $token,
        'refreshToken' => "{$token}-refresh",
        'expiresIn' => 60 * 86400,
        'approvedScopes' => ['openid', 'profile', 'w_member_social'],
    ]), $owner);
}

it('keeps the organizations token when the member then connects their profile', function () {
    $organization = separateStoreOrganizations($this->owner);
    $personal = separateStoreProfile($this->owner);

    $tokens = app(SocialTokens::class);

    expect($tokens->validAccessTokenFor($organization))->toBe('org-token')
        ->and($tokens->validAccessTokenFor($personal))->toBe('profile-token')
        ->and($organization->fresh()->credential->refresh_token)->toBe('org-token-refresh')
        ->and($organization->fresh()->credential->scopes)->toBe(['r_organization_admin', 'w_organization_social'])
        ->and($personal->credential->scopes)->toBe(['openid', 'profile', 'w_member_social'])
        ->and($organization->fresh()->credential->purpose)->toBe(StoreLinkedInOrganizations::PURPOSE)
        ->and($personal->credential->purpose)->toBe(SocialToken::DEFAULT_PURPOSE);
});

it('keeps the profile token when the member then connects their organizations', function () {
    $personal = separateStoreProfile($this->owner);
    $organization = separateStoreOrganizations($this->owner);

    expect(app(SocialTokens::class)->validAccessTokenFor($personal))->toBe('profile-token')
        ->and($personal->fresh()->credential->scopes)->toBe(['openid', 'profile', 'w_member_social'])
        ->and($organization->social_token_id)->not->toBe($personal->fresh()->social_token_id);
});

it('updates each credential in place when its own flow runs again', function () {
    separateStoreOrganizations($this->owner);
    separateStoreProfile($this->owner);

    $organization = separateStoreOrganizations($this->owner, 'org-token-2');
    $personal = separateStoreProfile($this->owner, 'profile-token-2');

    expect(SocialToken::count())->toBe(2)
        ->and($organization->fresh()->credential->access_token)->toBe('org-token-2')
        ->and($personal->fresh()->credential->access_token)->toBe('profile-token-2');
});

it('allows one credential per holder, purpose and owner', function () {
    $keys = ['provider' => 'linkedin', 'provider_holder_id' => 'member-1', 'ownable_type' => $this->owner->getMorphClass(), 'ownable_id' => $this->owner->getKey()];

    SocialToken::create($keys);
    SocialToken::create($keys + ['purpose' => StoreLinkedInOrganizations::PURPOSE]);

    expect(fn () => SocialToken::create($keys + ['purpose' => StoreLinkedInOrganizations::PURPOSE]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('revokes only the profile credential when the member disconnects their profile', function () {
    $organization = separateStoreOrganizations($this->owner);
    $personal = separateStoreProfile($this->owner);

    app(SocialTokens::class)->disconnect($personal);

    expect($personal->fresh()->credential->status)->toBe(AccountStatus::Revoked)
        ->and($organization->fresh()->credential->status)->toBe(AccountStatus::Active)
        ->and(app(SocialTokens::class)->validAccessTokenFor($organization))->toBe('org-token');
});

it('does not revoke at the provider while another purpose of the same holder holds a grant', function () {
    FakeConnector::reset();
    $keys = ['provider' => 'fake', 'provider_holder_id' => 'holder-1', 'access_token' => 't', 'status' => AccountStatus::Active];
    $default = SocialToken::create($keys);
    SocialToken::create($keys + ['purpose' => 'other']);

    app(SocialTokens::class)->revoke($default);

    expect(FakeConnector::$revokeCalls)->toBe(0)
        ->and($default->fresh()->status)->toBe(AccountStatus::Revoked);
});

it('renews a credential only for the accounts that post with it, not for its sibling\'s', function () {
    Queue::fake();
    $organization = separateStoreOrganizations($this->owner);
    $personal = separateStoreProfile($this->owner);

    // The member's profile row was flagged by the app; the organizations still post.
    $personal->markNeedsReconnect('Flagged by the app.');
    SocialToken::query()->update(['renew_at' => now()->subMinute()]);

    Artisan::call('social-tokens:dispatch-renewals');

    Queue::assertPushed(RenewCredential::class, 1);
    Queue::assertPushed(RenewCredential::class, fn (RenewCredential $job) => $job->token->is($organization->credential));
});

it('still keeps a Meta user credential alive through the Pages that name its holder', function () {
    Queue::fake();
    $user = SocialToken::create(['provider' => 'facebook', 'provider_holder_id' => 'fb-1', 'status' => AccountStatus::Active, 'renew_at' => now()->subMinute()]);
    $page = SocialToken::create(['provider' => 'facebook', 'provider_holder_id' => 'page-1', 'status' => AccountStatus::Active]);
    SocialAccount::create(['provider' => 'facebook', 'provider_user_id' => 'page-1', 'provider_holder_id' => 'fb-1', 'social_token_id' => $page->getKey(), 'status' => AccountStatus::Active]);

    expect(SocialToken::query()->inUse()->whereKey($user->getKey())->exists())->toBeTrue();
});
