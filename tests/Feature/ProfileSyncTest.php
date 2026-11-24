<?php

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Pr4w\SocialTokens\Actions\SyncAccountProfile;
use Pr4w\SocialTokens\Connectors\XConnector;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\ProfileSyncOutcome;
use Pr4w\SocialTokens\Events\AccountProfileUpdated;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Pr4w\SocialTokens\Support\ProfileSyncResult;
use Pr4w\SocialTokens\Support\Settings;

/*
 * Names change, and Meta, LinkedIn and TikTok hand out picture URLs that are
 * signed and expire: the profile stored at connect went stale, and its picture
 * stopped loading. social-tokens:sync-profiles keeps them current.
 */

/** An active account and the credential it posts with, valid for days. */
function profileAccount(string $provider, array $account = [], array $credential = []): SocialAccount
{
    $token = SocialToken::create($credential + [
        'provider' => $provider === 'instagram' ? 'facebook' : $provider,
        'provider_holder_id' => $account['provider_holder_id'] ?? 'holder-1',
        'access_token' => "{$provider}-token",
        'refresh_token' => "{$provider}-refresh",
        'status' => AccountStatus::Active,
        'expires_at' => now()->addDays(10),
        'renew_at' => now()->addDays(5),
    ]);

    return SocialAccount::create($account + [
        'provider' => $provider,
        'provider_user_id' => 'user-1',
        'provider_holder_id' => 'holder-1',
        'social_token_id' => $token->getKey(),
        'name' => 'Old name',
        'nickname' => 'old_handle',
        'avatar' => 'https://cdn.example.com/old.jpg',
        'status' => AccountStatus::Active,
    ]);
}

function syncProfile(SocialAccount $account): ProfileSyncResult
{
    return app(SyncAccountProfile::class)->handle($account);
}

// The sync ------------------------------------------------------------------

it('updates what changed, and tells the app what it replaced', function () {
    Event::fake([AccountProfileUpdated::class]);
    Http::fake(['api.x.com/2/users/me*' => Http::response(['data' => [
        'id' => 'user-1', 'name' => 'New name', 'username' => 'old_handle', 'profile_image_url' => 'https://pbs.twimg.com/new.jpg',
    ]])]);
    $account = profileAccount('x');

    $result = syncProfile($account);

    expect($result->outcome)->toBe(ProfileSyncOutcome::Updated)
        ->and($result->previous)->toBe(['name' => 'Old name', 'avatar' => 'https://cdn.example.com/old.jpg'])
        ->and($account->fresh()->name)->toBe('New name')
        ->and($account->fresh()->nickname)->toBe('old_handle')
        ->and($account->fresh()->avatar)->toBe('https://pbs.twimg.com/new.jpg')
        ->and($account->name)->toBe('New name')
        ->and($account->isDirty())->toBeFalse();

    Event::assertDispatched(AccountProfileUpdated::class, fn (AccountProfileUpdated $event) => $event->account->is($account)
        && $event->previous === ['name' => 'Old name', 'avatar' => 'https://cdn.example.com/old.jpg']);
    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer x-token') && $request['user.fields'] === 'profile_image_url');
});

it('changes nothing and fires nothing when the profile is the same', function () {
    Event::fake([AccountProfileUpdated::class]);
    Http::fake(['api.x.com/2/users/me*' => Http::response(['data' => [
        'name' => 'Old name', 'username' => 'old_handle', 'profile_image_url' => 'https://cdn.example.com/old.jpg',
    ]])]);

    expect(syncProfile(profileAccount('x'))->outcome)->toBe(ProfileSyncOutcome::Unchanged);

    Event::assertNotDispatched(AccountProfileUpdated::class);
});

it('never erases a stored value with a blank one, nor stores a picture that is no URL', function () {
    Http::fake(['api.x.com/2/users/me*' => Http::response(['data' => ['name' => '  ', 'username' => null, 'profile_image_url' => 'javascript:alert(1)']])]);
    $account = profileAccount('x');

    expect(syncProfile($account)->outcome)->toBe(ProfileSyncOutcome::Unchanged)
        ->and($account->fresh()->only(['name', 'nickname', 'avatar']))->toBe([
            'name' => 'Old name', 'nickname' => 'old_handle', 'avatar' => 'https://cdn.example.com/old.jpg',
        ]);
});

it('leaves the profile, and the credential, as they are when the provider cannot be read', function (int $status) {
    Http::fake(['api.x.com/2/users/me*' => Http::response(['title' => 'error'], $status)]);
    $account = profileAccount('x');

    $result = syncProfile($account);

    expect($result->outcome)->toBe(ProfileSyncOutcome::Failed)
        ->and($account->fresh()->name)->toBe('Old name')
        ->and($account->fresh()->status)->toBe(AccountStatus::Active)
        ->and($account->credential->fresh()->status)->toBe(AccountStatus::Active)
        ->and($account->credential->fresh()->failed_checks)->toBe(0);
})->with(['an outage' => [503], 'an unexpected answer' => [400]]);

it('reads nothing when the token was not granted the profile', function () {
    Http::fake();
    $account = profileAccount('x', credential: ['scopes' => ['tweet.read', 'tweet.write', 'offline.access']]);

    expect(syncProfile($account)->outcome)->toBe(ProfileSyncOutcome::Unavailable);

    Http::assertNothingSent();
});

it('takes a 401 or 403 on the profile as a missing grant, not a failure', function (int $status) {
    Http::fake(['api.x.com/2/users/me*' => Http::response(['title' => 'Forbidden'], $status)]);

    expect(syncProfile(profileAccount('x'))->outcome)->toBe(ProfileSyncOutcome::Unavailable);
})->with([401, 403]);

it('skips accounts that are not active, or whose credential needs a reconnect', function () {
    Http::fake();
    $flagged = profileAccount('x', ['status' => AccountStatus::NeedsReconnect]);
    $expired = profileAccount('x', ['provider_user_id' => 'user-2'], ['expires_at' => now()->subDay(), 'refresh_token' => null, 'renew_at' => now()->subDay()]);

    expect(syncProfile($flagged)->outcome)->toBe(ProfileSyncOutcome::Skipped)
        ->and(syncProfile($expired)->outcome)->toBe(ProfileSyncOutcome::Skipped);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'users/me'));
});

it('does not write over an account flagged during the read', function () {
    $account = profileAccount('x');
    Http::fake(['api.x.com/2/users/me*' => function () use ($account) {
        SocialAccount::query()->whereKey($account->getKey())->update(['status' => AccountStatus::NeedsReconnect->value]);

        return Http::response(['data' => ['name' => 'New name']]);
    }]);

    expect(syncProfile($account)->outcome)->toBe(ProfileSyncOutcome::Skipped)
        ->and($account->fresh()->name)->toBe('Old name');
});

// What each provider is asked ---------------------------------------------

it('reads a Facebook Page with its own Page token', function () {
    Http::fake(['graph.facebook.com/*/page-1*' => Http::response(['name' => 'Acme', 'picture' => ['data' => ['url' => 'https://scontent.xx.fbcdn.net/p.jpg?oe=1']]])]);
    $page = profileAccount('facebook', ['provider_user_id' => 'page-1'], ['provider_holder_id' => 'page-1', 'expires_at' => null, 'renew_at' => null]);

    syncProfile($page);

    expect($page->fresh()->name)->toBe('Acme')
        ->and($page->fresh()->avatar)->toBe('https://scontent.xx.fbcdn.net/p.jpg?oe=1');
    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer facebook-token') && $request['fields'] === 'name,picture{url}');
});

it('reads an Instagram account with the Facebook user\'s token', function () {
    Http::fake(['graph.facebook.com/*/ig-1*' => Http::response(['username' => 'acme.studio', 'profile_picture_url' => 'https://scontent.cdninstagram.com/a.jpg'])]);
    $account = profileAccount('instagram', ['provider_user_id' => 'ig-1']);

    syncProfile($account);

    expect($account->fresh()->only(['name', 'nickname', 'avatar']))->toBe([
        'name' => 'acme.studio', 'nickname' => 'acme.studio', 'avatar' => 'https://scontent.cdninstagram.com/a.jpg',
    ]);
    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer instagram-token'));
});

it('takes a Meta permission error as a missing grant', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 10, 'message' => 'Application does not have permission', 'type' => 'OAuthException']], 400)]);

    expect(syncProfile(profileAccount('instagram', ['provider_user_id' => 'ig-1']))->outcome)->toBe(ProfileSyncOutcome::Unavailable);
});

/*
 * A reconnect stores what the driver maps (StoreAccountFromSocialite): a sync
 * storing another value for the same attribute would swap it back and forth,
 * an AccountProfileUpdated each time. Instagram's driver stores the username
 * as the name, as InstagramConnector does; Threads' stores no name.
 */
it('reads Threads and Instagram Login accounts on their own /me, with the name and nickname a connection stores', function (string $provider, string $host, string $picture, string $name, string $nickname) {
    Http::fake(["{$host}/*" => Http::response(['username' => 'acme', 'name' => 'Acme Studio', $picture => 'https://cdn.example.com/new.jpg'])]);
    $account = profileAccount($provider);

    syncProfile($account);

    expect($account->fresh()->only(['name', 'nickname', 'avatar']))->toBe(['name' => $name, 'nickname' => $nickname, 'avatar' => 'https://cdn.example.com/new.jpg']);
    Http::assertSent(fn (Request $request) => $request['fields'] === "username,{$picture}" && $request['access_token'] === "{$provider}-token");
})->with([
    // Threads' driver stores no name, the username as nickname
    'Threads: the name is left alone' => ['threads', 'graph.threads.net', 'threads_profile_picture_url', 'Old name', 'acme'],
    // Instagram's driver stores the username as name, and no nickname
    'Instagram Login: the username as name, the nickname left alone' => ['instagram_login', 'graph.instagram.com', 'profile_picture_url', 'acme', 'old_handle'],
]);

it('stores the same name for an Instagram account through Facebook and through Instagram Login', function () {
    Http::fake([
        'graph.facebook.com/*/ig-1*' => Http::response(['username' => 'acme', 'profile_picture_url' => 'https://cdn.example.com/a.jpg']),
        'graph.instagram.com/*' => Http::response(['username' => 'acme', 'name' => 'Acme Studio', 'profile_picture_url' => 'https://cdn.example.com/a.jpg']),
    ]);
    $throughFacebook = profileAccount('instagram', ['provider_user_id' => 'ig-1']);
    $throughLogin = profileAccount('instagram_login', ['provider_user_id' => 'ig-login-1'], ['provider_holder_id' => 'holder-2']);

    syncProfile($throughFacebook);
    syncProfile($throughLogin);

    expect($throughFacebook->fresh()->name)->toBe('acme')
        ->and($throughLogin->fresh()->name)->toBe('acme');
});

it('reads the LinkedIn member\'s own profile from /userinfo', function () {
    Http::fake(['api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'member-1', 'name' => 'Ada Lovelace', 'picture' => 'https://media.licdn.com/ada.jpg?e=1'])]);
    $member = profileAccount('linkedin', ['provider_user_id' => 'member-1', 'provider_holder_id' => 'member-1']);

    syncProfile($member);

    expect($member->fresh()->name)->toBe('Ada Lovelace')
        ->and($member->fresh()->avatar)->toBe('https://media.licdn.com/ada.jpg?e=1')
        ->and($member->fresh()->nickname)->toBe('old_handle');
});

/**
 * The member as GET /v2/me answers with r_basicprofile and the picture's
 * playableStreams decorated (Profile API), its sizes out of order.
 */
function linkedInMe(): array
{
    $size = fn (int $width) => [
        'data' => ['com.linkedin.digitalmedia.mediaartifact.StillImage' => ['storageSize' => ['width' => $width, 'height' => $width]]],
        'identifiers' => [['identifier' => "https://media.licdn.com/ada-{$width}.jpg?e=1", 'identifierType' => 'EXTERNAL_URL']],
    ];

    return [
        'id' => 'member-1',
        'localizedFirstName' => 'Ada',
        'localizedLastName' => 'Lovelace',
        'profilePicture' => [
            'displayImage' => 'urn:li:digitalmediaAsset:C4E03AQ',
            'displayImage~' => ['elements' => [$size(100), $size(800), $size(400)]],
        ],
    ];
}

/*
 * Community Management apps are granted r_basicprofile, not OpenID's
 * profile: /userinfo refuses them, the Profile API answers.
 */
it('reads the LinkedIn member\'s profile from /v2/me with r_basicprofile', function () {
    Http::fake(['api.linkedin.com/v2/me*' => Http::response(linkedInMe())]);
    $member = profileAccount('linkedin', ['provider_user_id' => 'member-1', 'provider_holder_id' => 'member-1'], [
        'provider_holder_id' => 'member-1',
        'scopes' => ['r_basicprofile', 'w_member_social', 'rw_organization_admin', 'w_organization_social'],
    ]);

    expect(syncProfile($member)->outcome)->toBe(ProfileSyncOutcome::Updated)
        ->and($member->fresh()->name)->toBe('Ada Lovelace')
        ->and($member->fresh()->avatar)->toBe('https://media.licdn.com/ada-800.jpg?e=1')
        ->and($member->fresh()->nickname)->toBe('old_handle');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request['projection'] === '(id,localizedFirstName,localizedLastName,profilePicture(displayImage~:playableStreams))'
        && $request->hasHeader('Authorization', 'Bearer linkedin-token')
        && $request->hasHeader('X-Restli-Protocol-Version', '2.0.0'));
});

it('asks /v2/me when /userinfo refuses a member whose scopes are not on record', function () {
    Http::fake([
        'api.linkedin.com/v2/userinfo' => Http::response(['message' => 'Not enough permissions to access: userinfo.GET.NO_VERSION'], 403),
        'api.linkedin.com/v2/me*' => Http::response(linkedInMe()),
    ]);
    $member = profileAccount('linkedin', ['provider_user_id' => 'member-1', 'provider_holder_id' => 'member-1']);

    syncProfile($member);

    expect($member->fresh()->name)->toBe('Ada Lovelace');
    Http::assertSentCount(2);
});

it('reads only /userinfo when the profile scope is granted', function () {
    Http::fake(['api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'member-1', 'name' => 'Ada Lovelace', 'picture' => 'https://media.licdn.com/ada.jpg?e=1'])]);
    $member = profileAccount('linkedin', ['provider_user_id' => 'member-1', 'provider_holder_id' => 'member-1'], [
        'provider_holder_id' => 'member-1',
        'scopes' => ['openid', 'profile', 'r_basicprofile'],
    ]);

    syncProfile($member);

    expect($member->fresh()->avatar)->toBe('https://media.licdn.com/ada.jpg?e=1');
    Http::assertSentCount(1);
});

it('leaves the LinkedIn member alone without a profile scope, or when /v2/me refuses', function (array $scopes, int $status) {
    Http::fake(['api.linkedin.com/v2/me*' => Http::response(['message' => 'Not enough permissions'], $status)]);
    $member = profileAccount('linkedin', ['provider_user_id' => 'member-1', 'provider_holder_id' => 'member-1'], ['provider_holder_id' => 'member-1', 'scopes' => $scopes]);

    expect(syncProfile($member)->outcome)->toBe(ProfileSyncOutcome::Unavailable)
        ->and($member->fresh()->name)->toBe('Old name');
})->with([
    'no profile scope' => [['w_member_social', 'w_organization_social'], 200],
    '/v2/me refused' => [['r_basicprofile', 'w_member_social'], 403],
]);

it('reads LinkedIn organizations from one listing of the member\'s', function () {
    Http::fake(linkedInRest(Http::response(['elements' => [
        ['role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'organization' => 'urn:li:organization:111', 'organization~' => ['id' => 111, 'localizedName' => 'Acme']],
        ['role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'organization' => 'urn:li:organization:222', 'organization~' => ['id' => 222, 'localizedName' => 'Globex']],
    ]])));
    $acme = profileAccount('linkedin', ['provider_user_id' => '111', 'provider_holder_id' => 'member-1'], ['provider_holder_id' => 'member-1']);
    $globex = SocialAccount::create(['provider' => 'linkedin', 'provider_user_id' => '222', 'provider_holder_id' => 'member-1', 'social_token_id' => $acme->social_token_id, 'name' => 'Old', 'status' => AccountStatus::Active]);
    $gone = SocialAccount::create(['provider' => 'linkedin', 'provider_user_id' => '333', 'provider_holder_id' => 'member-1', 'social_token_id' => $acme->social_token_id, 'name' => 'Initech', 'status' => AccountStatus::Active]);

    $this->artisan('social-tokens:sync-profiles')->assertSuccessful();

    expect($acme->fresh()->name)->toBe('Acme')
        ->and($globex->fresh()->name)->toBe('Globex')
        ->and($gone->fresh()->name)->toBe('Initech')
        ->and($gone->fresh()->status)->toBe(AccountStatus::Active); // reconciliation is the connection's job

    Http::assertSentCount(2); // the grants, then both names in one batch (no logos)
});

it('counts an organization LinkedIn did not describe as a failed read, keeping its name', function () {
    Http::fake(linkedInRest(Http::response(['elements' => [
        ['role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'organization' => 'urn:li:organization:111'],
    ]])));
    $acme = profileAccount('linkedin', ['provider_user_id' => '111', 'provider_holder_id' => 'member-1', 'name' => 'Acme'], ['provider_holder_id' => 'member-1']);

    $result = app(SyncAccountProfile::class)->handle($acme);

    expect($result->outcome)->toBe(ProfileSyncOutcome::Failed)
        ->and($result->reason)->toContain('did not describe')
        ->and($acme->fresh()->name)->toBe('Acme')
        ->and($acme->fresh()->avatar)->toBe('https://cdn.example.com/old.jpg');
});

/*
 * TikTok's driver stores avatar_large_url at connect: the sync stores the same
 * picture. TikTok answers a missing scope, and an invalid token, with HTTP 401
 * and says which in error.code (its v2 error handling doc).
 */
it('reads TikTok\'s display name and large avatar, and tells a missing scope from an invalid token', function () {
    Http::fake(['open.tiktokapis.com/v2/user/info/*' => Http::sequence()
        ->push(['data' => ['user' => ['display_name' => 'Acme', 'avatar_url' => 'https://p16-sign.tiktokcdn.com/small.jpg', 'avatar_large_url' => 'https://p16-sign.tiktokcdn.com/large.jpg?x-expires=1']], 'error' => ['code' => 'ok']])
        ->push(['data' => [], 'error' => ['code' => 'scope_not_authorized', 'message' => 'no']], 401)
        ->push(['data' => [], 'error' => ['code' => 'access_token_invalid', 'message' => 'invalid']], 401),
    ]);
    $account = profileAccount('tiktok');

    expect(syncProfile($account)->outcome)->toBe(ProfileSyncOutcome::Updated)
        ->and($account->fresh()->avatar)->toBe('https://p16-sign.tiktokcdn.com/large.jpg?x-expires=1')
        ->and(syncProfile($account->fresh())->outcome)->toBe(ProfileSyncOutcome::Unavailable)
        ->and(syncProfile($account->fresh()))->outcome->toBe(ProfileSyncOutcome::Failed)->reason->toContain('access_token_invalid');

    Http::assertSent(fn (Request $request) => $request['fields'] === 'open_id,display_name,avatar_large_url');
});

/*
 * The driver stores the username as the nickname. The username needs
 * user.info.profile: asked for without it, TikTok refuses the whole read.
 */
it('reads the TikTok username as the nickname only when user.info.profile is granted', function (?array $scopes, string $fields, string $nickname) {
    Http::fake(['open.tiktokapis.com/v2/user/info/*' => Http::response(['data' => ['user' => [
        'display_name' => 'Acme', 'avatar_large_url' => 'https://p16-sign.tiktokcdn.com/large.jpg', 'username' => 'acme.studio',
    ]], 'error' => ['code' => 'ok']])]);
    $account = profileAccount('tiktok', credential: $scopes === null ? [] : ['scopes' => $scopes]);

    syncProfile($account);

    expect($account->fresh()->only(['name', 'nickname']))->toBe(['name' => 'Acme', 'nickname' => $nickname]);
    Http::assertSent(fn (Request $request) => $request['fields'] === $fields);
})->with([
    'granted' => [['user.info.basic', 'user.info.profile', 'video.publish'], 'open_id,display_name,avatar_large_url,username', 'acme.studio'],
    'not granted' => [['user.info.basic', 'video.publish'], 'open_id,display_name,avatar_large_url', 'old_handle'],
    'scopes not on record' => [null, 'open_id,display_name,avatar_large_url', 'old_handle'],
]);

it('reads the rest of the TikTok profile when the username is refused', function () {
    Http::fake(['open.tiktokapis.com/v2/user/info/*' => fn (Request $request) => str_contains($request['fields'], 'username')
        ? Http::response(['data' => [], 'error' => ['code' => 'scope_not_authorized', 'message' => 'no']], 401)
        : Http::response(['data' => ['user' => ['display_name' => 'Acme', 'avatar_large_url' => 'https://p16-sign.tiktokcdn.com/large.jpg']], 'error' => ['code' => 'ok']])]);
    $account = profileAccount('tiktok', credential: ['scopes' => ['user.info.basic', 'user.info.profile']]);

    expect(syncProfile($account)->outcome)->toBe(ProfileSyncOutcome::Updated)
        ->and($account->fresh()->only(['name', 'nickname']))->toBe(['name' => 'Acme', 'nickname' => 'old_handle']);

    Http::assertSentCount(2);
});

it('reads the Google account from /userinfo', function () {
    Http::fake(['openidconnect.googleapis.com/v1/userinfo' => Http::response(['name' => 'Acme Channel', 'picture' => 'https://lh3.googleusercontent.com/a'])]);
    $account = profileAccount('google');

    syncProfile($account);

    expect($account->fresh()->name)->toBe('Acme Channel');
});

it('reads Pinterest where the app works, the Sandbox included', function () {
    config(['services.pinterest.sandbox' => true]);
    Http::fake(['api-sandbox.pinterest.com/v5/user_account' => Http::response(['username' => 'acme', 'business_name' => 'Acme Inc.', 'profile_image' => 'https://i.pinimg.com/a.jpg'])]);
    $account = profileAccount('pinterest');

    syncProfile($account);

    expect($account->fresh()->only(['name', 'nickname', 'avatar']))->toBe(['name' => 'Acme Inc.', 'nickname' => 'acme', 'avatar' => 'https://i.pinimg.com/a.jpg']);
});

it('reads Bluesky from the public AppView, without asking for a token', function () {
    Http::fake(['public.api.bsky.app/xrpc/app.bsky.actor.getProfile*' => Http::response(['did' => 'did:plc:abc', 'handle' => 'acme.bsky.social', 'displayName' => '', 'avatar' => 'https://cdn.bsky.app/a.jpg'])]);
    // An expired access token: asking for one would renew it
    $account = profileAccount('bluesky', ['provider_user_id' => 'did:plc:abc'], ['expires_at' => now()->subMinute()]);

    syncProfile($account);

    expect($account->fresh()->only(['name', 'nickname', 'avatar']))->toBe(['name' => 'acme.bsky.social', 'nickname' => 'acme.bsky.social', 'avatar' => 'https://cdn.bsky.app/a.jpg']);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request['actor'] === 'did:plc:abc');
});

// The command -------------------------------------------------------------

it('syncs every active account and logs one warning per provider that failed', function () {
    config(['social-tokens.profile_sync_except' => []]);
    Log::spy();
    Http::fake([
        'api.x.com/2/users/me*' => Http::response(['data' => ['name' => 'New name']]),
        'open.tiktokapis.com/*' => Http::response([], 503),
    ]);
    $x = profileAccount('x');
    profileAccount('tiktok');
    profileAccount('tiktok', ['provider_user_id' => 'user-2'], ['provider_holder_id' => 'holder-2']);
    profileAccount('x', ['provider_user_id' => 'user-3', 'status' => AccountStatus::Revoked], ['provider_holder_id' => 'holder-3']);

    $this->artisan('social-tokens:sync-profiles')
        ->expectsOutputToContain('Synced 1 profile(s): 1 updated, 0 unchanged.')
        ->expectsOutputToContain('2 profile(s) could not be read')
        ->assertSuccessful();

    expect($x->fresh()->name)->toBe('New name');
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => str_contains($message, 'Could not read 2 tiktok profile(s)')
        && $context['accounts'] === 2)->once();
});

it('syncs only the providers asked for', function () {
    Http::fake(['api.x.com/2/users/me*' => Http::response(['data' => ['name' => 'New name']])]);
    profileAccount('x');
    profileAccount('tiktok');

    $this->artisan('social-tokens:sync-profiles', ['--provider' => ['x']])->expectsOutputToContain('1 updated')->assertSuccessful();

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'tiktok'));
});

it('is scheduled daily in the background, and can be turned off', function () {
    $events = function () {
        app()->forgetInstance(Schedule::class);
        Facade::clearResolvedInstance(Schedule::class);

        return collect(app(Schedule::class)->events())
            ->filter(fn (ScheduledEvent $event) => str_contains($event->command ?? '', 'social-tokens:sync-profiles'))
            ->map(fn (ScheduledEvent $event) => [$event->expression, $event->withoutOverlapping, $event->onOneServer, $event->runInBackground])
            ->values()->all();
    };

    expect($events())->toBe([['0 0 * * *', true, true, true]]);

    config(['social-tokens.profile_sync_schedule' => null]);
    expect($events())->toBe([]);
});

// X bills every read --------------------------------------------------------

it('leaves X out of the daily run unless asked', function () {
    Http::fake([
        'api.x.com/2/users/me*' => Http::response(['data' => ['name' => 'New name']]),
        'api.pinterest.com/v5/user_account' => Http::response(['username' => 'acme']),
    ]);
    $x = profileAccount('x');
    profileAccount('pinterest');

    $this->artisan('social-tokens:sync-profiles')->expectsOutputToContain('Synced 1 profile(s)')->assertSuccessful();

    expect($x->fresh()->name)->toBe('Old name');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'api.x.com'));

    // Named on the command line, or taken out of the list, it is synced
    $this->artisan('social-tokens:sync-profiles', ['--provider' => ['x']])->expectsOutputToContain('1 updated');

    expect($x->fresh()->name)->toBe('New name');
});

it('reads profile_sync_except as a list or a comma-separated string, null (the billed connectors) when missing', function (mixed $value, ?array $except) {
    config(['social-tokens.profile_sync_except' => $value]);

    expect(Settings::profileSyncExcept())->toBe($except);
})->with([
    'the shipped default' => [null, null],
    'a string' => ['x, tiktok', ['x', 'tiktok']],
    'nothing left out' => [[], []],
    'a list of 3.6.1' => [['x'], ['x']],
    'unreadable' => [42, null],
]);

it('leaves X out by default under whatever key the app registered it', function () {
    config(['social-tokens.connectors.twitter' => ['driver' => XConnector::class, 'credentials' => 'x']]);
    app()->forgetInstance(ConnectorRegistry::class);
    Http::fake(['api.x.com/2/users/me*' => Http::response(['data' => ['name' => 'New name']])]);
    $twitter = profileAccount('twitter');

    $this->artisan('social-tokens:sync-profiles')->assertSuccessful();

    expect($twitter->fresh()->name)->toBe('Old name');
    Http::assertNothingSent();

    // An explicit list replaces the default: [] syncs it
    config(['social-tokens.profile_sync_except' => []]);
    $this->artisan('social-tokens:sync-profiles')->assertSuccessful();

    expect($twitter->fresh()->name)->toBe('New name');
});

it('never renews a token to read a profile', function () {
    Http::fake();
    // TikTok, its 24-hour access token expired, its refresh token still good
    $account = profileAccount('tiktok', credential: ['expires_at' => now()->subMinute(), 'renew_at' => now()->subHour()]);

    $result = syncProfile($account);

    expect($result->outcome)->toBe(ProfileSyncOutcome::Skipped)
        ->and($result->reason)->toContain('left to the renewal')
        ->and($account->credential->fresh()->access_token)->toBe('tiktok-token');

    Http::assertNothingSent();
});
