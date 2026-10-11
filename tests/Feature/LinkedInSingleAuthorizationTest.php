<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Pr4w\SocialTokens\Actions\MergeLinkedInCredentials;
use Pr4w\SocialTokens\Actions\StoreAccountFromSocialite;
use Pr4w\SocialTokens\Actions\StoreLinkedInOrganizations;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\AccountConnected;
use Pr4w\SocialTokens\Jobs\RenewCredential;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Pr4w\SocialTokens\Tests\Fixtures\Owner;

/*
 * Single authorization mode is the default. An app whose one LinkedIn app (Community Management) asks for the scopes of
 * the personal profile and of the organizations at once gets ONE token for
 * both. Stored on two credentials (the profile's and purpose
 * 'organizations'), it was renewed twice with the same refresh token. In
 * single authorization mode they share the member's default credential.
 */

beforeEach(function () {
    $this->organizations = ['111' => 'Acme', '222' => 'Globex'];

    Http::fake([
        'api.linkedin.com/v2/organizationAcls*' => fn () => Http::response(['elements' => collect($this->organizations)
            ->map(fn (string $name, string $id) => ['role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'organization~' => ['id' => $id, 'localizedName' => $name]])
            ->values()->all()]),
        '*' => Http::response([]),
    ]);

    $this->owner = Owner::create();
});

function singleAuthorizationMode(bool $on): void
{
    config(['social-tokens.connectors.linkedin.single_authorization' => $on]);
    app()->forgetInstance(ConnectorRegistry::class); // connectors read their config once
}

/** @return Collection<int, SocialAccount> */
function singleStoreOrganizations(Owner $owner, string $token = 'member-token', ?array $member = null, bool $restore = false)
{
    return app(StoreLinkedInOrganizations::class)->handle(
        accessToken: $token,
        memberId: 'member-1',
        owner: $owner,
        refreshToken: "{$token}-refresh",
        expiresAt: now()->addDays(60),
        scopes: ['openid', 'profile', 'w_member_social', 'r_organization_admin', 'w_organization_social'],
        restoreDisconnected: $restore,
        member: $member,
    );
}

function singleStoreProfile(Owner $owner, string $token = 'member-token'): SocialAccount
{
    return app(StoreAccountFromSocialite::class)->handle('linkedin', socialiteUser([
        'id' => 'member-1',
        'token' => $token,
        'refreshToken' => "{$token}-refresh",
        'expiresIn' => 60 * 86400,
        'approvedScopes' => ['openid', 'profile', 'w_member_social', 'r_organization_admin', 'w_organization_social'],
    ]), $owner);
}

it('is on by default, in a published config without the option too', function () {
    $linkedin = config('social-tokens.connectors.linkedin');

    expect($linkedin['single_authorization'])->toBeTrue();

    unset($linkedin['single_authorization']);
    config(['social-tokens.connectors.linkedin' => $linkedin]);
    app()->forgetInstance(ConnectorRegistry::class);

    expect(app(ConnectorRegistry::class)->for('linkedin')->singleAuthorization())->toBeTrue();
});

it('stores the organizations on the member\'s default credential, shared with the profile', function () {
    $organizations = singleStoreOrganizations($this->owner);
    $personal = singleStoreProfile($this->owner, 'member-token-2');

    expect(SocialToken::count())->toBe(1)
        ->and($personal->credential->purpose)->toBe(SocialToken::DEFAULT_PURPOSE)
        ->and($organizations->pluck('social_token_id')->unique()->all())->toBe([$personal->social_token_id])
        ->and(app(SocialTokens::class)->validAccessTokenFor($organizations->first()->fresh()))->toBe('member-token-2');
});

it('stores the member\'s profile from the same call', function () {
    Event::fake([AccountConnected::class]);

    $accounts = singleStoreOrganizations($this->owner, member: ['name' => 'Ada Lovelace', 'avatar' => 'https://example.com/ada.png']);
    $personal = $accounts->first();

    expect($accounts->pluck('provider_user_id')->all())->toBe(['member-1', '111', '222'])
        ->and($personal->name)->toBe('Ada Lovelace')
        ->and($personal->avatar)->toBe('https://example.com/ada.png')
        ->and($personal->provider_holder_id)->toBe('member-1')
        ->and($personal->status)->toBe(AccountStatus::Active)
        ->and($personal->ownable->is($this->owner))->toBeTrue()
        ->and($accounts->pluck('social_token_id')->unique()->count())->toBe(1)
        ->and(SocialToken::sole()->access_token)->toBe('member-token');

    Event::assertDispatchedTimes(AccountConnected::class, 3);

    // A re-sync that no longer lists an organization flags it, never the profile.
    $this->organizations = ['111' => 'Acme'];
    singleStoreOrganizations($this->owner, member: ['name' => 'Ada Lovelace']);

    expect($personal->fresh()->status)->toBe(AccountStatus::Active)
        ->and(SocialAccount::where('provider_user_id', '222')->sole()->status)->toBe(AccountStatus::NeedsReconnect);
});

it('refuses the member\'s profile outside single authorization mode, before calling LinkedIn', function () {
    singleAuthorizationMode(false);

    expect(fn () => singleStoreOrganizations($this->owner, member: ['name' => 'Ada']))
        ->toThrow(InvalidArgumentException::class, 'single authorization mode');

    Http::assertNothingSent();
    expect(SocialToken::count())->toBe(0);
});

it('folds a separate organizations credential into the default one once the mode is on', function () {
    singleAuthorizationMode(false);
    singleStoreProfile($this->owner, 'profile-token');
    singleStoreOrganizations($this->owner, 'org-token');
    expect(SocialToken::count())->toBe(2);

    singleAuthorizationMode(true);
    $organizations = singleStoreOrganizations($this->owner, 'member-token');

    $credential = SocialToken::sole();

    expect($credential->purpose)->toBe(SocialToken::DEFAULT_PURPOSE)
        ->and($credential->access_token)->toBe('member-token')
        ->and($credential->refresh_token)->toBe('member-token-refresh')
        ->and(SocialAccount::pluck('social_token_id')->unique()->all())->toBe([$credential->getKey()])
        ->and($organizations->pluck('social_token_id')->unique()->all())->toBe([$credential->getKey()]);
});

it('folds it in from the profile flow too', function () {
    singleAuthorizationMode(false);
    singleStoreOrganizations($this->owner, 'org-token');
    singleStoreProfile($this->owner, 'profile-token');

    singleAuthorizationMode(true);
    $personal = singleStoreProfile($this->owner, 'member-token');

    expect(SocialToken::count())->toBe(1)
        ->and(SocialAccount::pluck('social_token_id')->unique()->all())->toBe([$personal->social_token_id])
        ->and($personal->credential->access_token)->toBe('member-token');
});

it('keeps the fresher token when it folds two credentials', function () {
    singleAuthorizationMode(false);
    $personal = singleStoreProfile($this->owner, 'profile-token');
    $organization = singleStoreOrganizations($this->owner, 'org-token')->first();
    $separate = $organization->credential;
    $separate->forceFill(['expires_at' => now()->addDays(70), 'renew_at' => now()->addDays(65)])->save();

    $merged = app(MergeLinkedInCredentials::class)->handle($separate);

    expect($merged->getKey())->toBe($personal->social_token_id)
        ->and($merged->access_token)->toBe('org-token')
        ->and($merged->refresh_token)->toBe('org-token-refresh')
        ->and($merged->expires_at->equalTo($separate->expires_at))->toBeTrue()
        ->and($merged->renew_at->equalTo($separate->renew_at))->toBeTrue()
        ->and(SocialToken::find($separate->getKey()))->toBeNull()
        ->and($organization->fresh()->social_token_id)->toBe($merged->getKey());
});

it('keeps the default credential\'s token when it is the fresher, or the only active one', function () {
    singleAuthorizationMode(false);
    $personal = singleStoreProfile($this->owner, 'profile-token');
    $separate = singleStoreOrganizations($this->owner, 'org-token')->first()->credential;
    $separate->forceFill(['status' => AccountStatus::NeedsReconnect, 'expires_at' => now()->addDays(90)])->save();

    $merged = app(MergeLinkedInCredentials::class)->handle($separate);

    expect($merged->getKey())->toBe($personal->social_token_id)
        ->and($merged->access_token)->toBe('profile-token')
        ->and($merged->status)->toBe(AccountStatus::Active);
});

it('makes a separate credential the default one when the member has no other', function () {
    singleAuthorizationMode(false);
    $separate = singleStoreOrganizations($this->owner, 'org-token')->first()->credential;

    $merged = app(MergeLinkedInCredentials::class)->handle($separate);

    expect($merged->getKey())->toBe($separate->getKey())
        ->and($merged->fresh()->purpose)->toBe(SocialToken::DEFAULT_PURPOSE)
        ->and($merged->fresh()->access_token)->toBe('org-token');
});

it('keeps the shared credential while an account still posts with it', function () {
    $accounts = singleStoreOrganizations($this->owner, member: ['name' => 'Ada']);
    $tokens = app(SocialTokens::class);

    $tokens->disconnect($accounts[0]); // the profile
    expect(SocialToken::sole()->status)->toBe(AccountStatus::Active);

    $tokens->disconnect($accounts[1]);
    expect(SocialToken::sole()->status)->toBe(AccountStatus::Active);

    $tokens->disconnect($accounts[2]);
    expect(SocialToken::sole()->status)->toBe(AccountStatus::Revoked);
});

it('leaves a disconnected profile out of a reconnect unless asked to restore it', function () {
    $personal = singleStoreOrganizations($this->owner, member: ['name' => 'Ada'])->first();
    app(SocialTokens::class)->disconnect($personal);

    expect(singleStoreOrganizations($this->owner, member: ['name' => 'Ada'])->pluck('provider_user_id')->all())->toBe(['111', '222'])
        ->and($personal->fresh()->status)->toBe(AccountStatus::Revoked);

    expect(singleStoreOrganizations($this->owner, member: ['name' => 'Ada'], restore: true)->pluck('provider_user_id')->all())
        ->toBe(['member-1', '111', '222'])
        ->and($personal->fresh()->status)->toBe(AccountStatus::Active);
});

it('renews the one token once', function () {
    Queue::fake();
    singleStoreOrganizations($this->owner, member: ['name' => 'Ada']);
    SocialToken::query()->update(['renew_at' => now()->subMinute()]);

    $this->artisan('social-tokens:dispatch-renewals')->assertSuccessful();

    Queue::assertPushed(RenewCredential::class, 1);
});

it('folds every member\'s credentials with the merge command, and only in single authorization mode', function () {
    singleAuthorizationMode(false);
    singleStoreProfile($this->owner, 'profile-token');
    singleStoreOrganizations($this->owner, 'org-token');

    $this->artisan('social-tokens:merge-linkedin-credentials')
        ->expectsOutputToContain('Turn single authorization mode on first')
        ->assertExitCode(2);
    expect(SocialToken::count())->toBe(2);

    singleAuthorizationMode(true);

    $this->artisan('social-tokens:merge-linkedin-credentials')
        ->expectsOutputToContain('Merged 1 LinkedIn organizations credential(s)')
        ->assertSuccessful();

    expect(SocialToken::count())->toBe(1)
        ->and(SocialAccount::pluck('social_token_id')->unique()->count())->toBe(1);
});
