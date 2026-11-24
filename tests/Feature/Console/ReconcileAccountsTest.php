<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Pr4w\SocialTokens\Actions\StoreInstagramAccounts;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\AccountConnected;
use Pr4w\SocialTokens\Events\AccountNeedsReconnect;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;

/*
 * A LinkedIn member who is no longer an organization's admin, or a Facebook
 * user who unlinked the Page behind an Instagram account, keeps a working
 * token: renewals and token checks saw nothing, the account stayed active
 * and every post as it failed, until the member's next connection reconciled
 * it. check-static now lists, every day, the accounts such a token can still
 * post as, and flags the missing ones, as a reconnect would.
 */

beforeEach(function () {
    // The token checks are tested elsewhere: only the listings here.
    config(['social-tokens.check_renewable' => false]);
});

/** A LinkedIn member's credential behind their profile and organizations 111 and 222. */
function reconcileLinkedIn(array $credential = []): SocialToken
{
    $token = SocialToken::create($credential + [
        'provider' => 'linkedin',
        'provider_holder_id' => 'member-1',
        'access_token' => 'member-token',
        'status' => AccountStatus::Active,
        'expires_at' => now()->addDays(30),
        'renew_at' => now()->addDays(25),
    ]);

    foreach (['member-1', '111', '222'] as $id) {
        SocialAccount::create([
            'provider' => 'linkedin',
            'provider_user_id' => $id,
            'provider_holder_id' => 'member-1',
            'social_token_id' => $token->getKey(),
            'status' => AccountStatus::Active,
        ]);
    }

    return $token;
}

/** LinkedIn lists these organizations (id => name), one answer per call. */
function reconcileOrganizations(array ...$answers): void
{
    $sequence = Http::sequence();

    foreach ($answers as $organizations) {
        $sequence->push(['elements' => collect($organizations)->map(fn (string $id) => [
            'role' => 'ADMINISTRATOR',
            'state' => 'APPROVED',
            'organization' => "urn:li:organization:{$id}",
            'organization~' => ['id' => (int) $id, 'localizedName' => "Org {$id}"],
        ])->values()->all()]);
    }

    Http::fake(['api.linkedin.com/v2/organizationAcls*' => $sequence]);
}

function reconcileAccount(string $provider, string $id): SocialAccount
{
    return SocialAccount::where('provider', $provider)->where('provider_user_id', $id)->sole();
}

it('flags an organization the member no longer administers, and nothing else', function () {
    Event::fake([AccountNeedsReconnect::class]);
    $token = reconcileLinkedIn();
    reconcileOrganizations(['111'], ['111']);

    $this->artisan('social-tokens:check-static')
        ->expectsOutputToContain('Checked 2 account(s) behind 1 shared credential(s), flagged 1.')
        ->assertSuccessful();

    expect(reconcileAccount('linkedin', '222')->status)->toBe(AccountStatus::NeedsReconnect)
        ->and(reconcileAccount('linkedin', '222')->last_error)->toBe('Organization no longer administered by the connected LinkedIn member.')
        ->and(reconcileAccount('linkedin', '111')->status)->toBe(AccountStatus::Active)
        ->and(reconcileAccount('linkedin', 'member-1')->status)->toBe(AccountStatus::Active) // the member's own profile is never listed
        ->and($token->fresh()->status)->toBe(AccountStatus::Active);

    Event::assertDispatchedTimes(AccountNeedsReconnect::class, 1);
    Http::assertSentCount(2); // the second read confirms it
    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer member-token'));
});

it('decides nothing on a single answer leaving an organization out', function () {
    reconcileLinkedIn();
    reconcileOrganizations(['111'], ['111', '222']);

    $this->artisan('social-tokens:check-static')->expectsOutputToContain('flagged 0.')->assertSuccessful();

    expect(reconcileAccount('linkedin', '222')->status)->toBe(AccountStatus::Active);
});

it('lists once when every account is there', function () {
    reconcileLinkedIn();
    reconcileOrganizations(['111', '222', '333']);

    $this->artisan('social-tokens:check-static')->expectsOutputToContain('Checked 2 account(s) behind 1 shared credential(s), flagged 0.');

    Http::assertSentCount(1);
});

/** A Facebook user's token behind Instagram accounts ig-1 and ig-2. */
function reconcileInstagram(): SocialToken
{
    $token = SocialToken::create([
        'provider' => 'facebook', 'provider_holder_id' => 'fb-user-1', 'access_token' => 'user-token', 'status' => AccountStatus::Active,
        'expires_at' => now()->addDays(40), 'renew_at' => now()->addDays(33),
    ]);

    foreach (['ig-1', 'ig-2'] as $id) {
        SocialAccount::create(['provider' => 'instagram', 'provider_user_id' => $id, 'provider_holder_id' => 'fb-user-1', 'social_token_id' => $token->getKey(), 'status' => AccountStatus::Active]);
    }

    return $token;
}

/** /me/accounts lists only ig-1's Page; Meta answers $igTwo when asked about ig-2 itself. */
function reconcileInstagramGraph(mixed $igTwo, int $status = 200): void
{
    Http::fake([
        'graph.facebook.com/*/me/accounts*' => Http::response(['data' => [
            ['id' => 'page-1', 'instagram_business_account' => ['id' => 'ig-1']],
            ['id' => 'page-2'], // no linked Instagram account
        ]]),
        'graph.facebook.com/*/ig-2*' => Http::response($igTwo, $status),
    ]);
}

it('flags an Instagram account Meta says the user no longer reaches', function (array $error) {
    reconcileInstagram();
    reconcileInstagramGraph(['error' => $error], 400);

    $this->artisan('social-tokens:check-static')->expectsOutputToContain('flagged 1.')->assertSuccessful();

    expect(reconcileAccount('instagram', 'ig-2')->status)->toBe(AccountStatus::NeedsReconnect)
        ->and(reconcileAccount('instagram', 'ig-2')->last_error)->toBe('Instagram account no longer managed by the connected Facebook user.')
        ->and(reconcileAccount('instagram', 'ig-1')->status)->toBe(AccountStatus::Active);

    Http::assertSent(fn (Request $request) => $request['fields'] === 'id,instagram_business_account{id}' && $request->hasHeader('Authorization', 'Bearer user-token'));
    Http::assertSent(fn (Request $request) => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/ig-2') && $request['fields'] === 'id');
})->with([
    'not found (100/33)' => [['code' => 100, 'error_subcode' => 33, 'type' => 'GraphMethodException', 'message' => "Object with ID 'ig-2' does not exist"]],
    'no permission (10)' => [['code' => 10, 'type' => 'OAuthException', 'message' => 'Application does not have permission for this action']],
    'no permission (200)' => [['code' => 200, 'type' => 'OAuthException', 'message' => 'Permissions error']],
]);

/*
 * Seen in production: an Instagram account the user reaches through Business
 * Manager is not on /me/accounts (only Pages with a role are), yet reads and
 * posts with the user token. 3.7.0 flagged it.
 */
it('leaves an Instagram account the token still reads, as one reached through Business Manager', function () {
    reconcileInstagram();
    reconcileInstagramGraph(['id' => 'ig-2']);

    $this->artisan('social-tokens:check-static')->expectsOutputToContain('flagged 0.')->assertSuccessful();

    expect(reconcileAccount('instagram', 'ig-2')->status)->toBe(AccountStatus::Active);
});

it('decides nothing when Meta does not answer about the account', function (int $status, array $body) {
    reconcileInstagram();
    reconcileInstagramGraph($body, $status);

    $this->artisan('social-tokens:check-static')->expectsOutputToContain('flagged 0.')->assertSuccessful();

    expect(reconcileAccount('instagram', 'ig-2')->status)->toBe(AccountStatus::Active);
})->with([
    'an outage' => [503, []],
    'a rate limit' => [400, ['error' => ['code' => 4, 'message' => 'Application request limit reached', 'type' => 'OAuthException']]],
    'a dead token' => [400, ['error' => ['code' => 190, 'message' => 'Error validating access token', 'type' => 'OAuthException']]],
    'an unexpected answer' => [200, ['id' => 'someone-else']],
]);

it('leaves an Instagram account the token still reads at reconnect too', function (mixed $answer, int $status, AccountStatus $expected) {
    $token = reconcileInstagram();
    reconcileInstagramGraph($answer, $status);
    Http::fake(['graph.facebook.com/*/me*' => Http::response(['id' => 'fb-user-1'])]);

    app(StoreInstagramAccounts::class)->handle(userToken: 'user-token', userId: 'fb-user-1', extend: false);

    expect(reconcileAccount('instagram', 'ig-2')->status)->toBe($expected);
})->with([
    'reached through Business Manager' => [['id' => 'ig-2'], 200, AccountStatus::Active],
    'out of reach' => [['error' => ['code' => 100, 'error_subcode' => 33, 'type' => 'GraphMethodException', 'message' => 'gone']], 400, AccountStatus::NeedsReconnect],
    'no answer' => [[], 503, AccountStatus::Active],
]);

it('decides nothing on a list it cannot read whole, and says so once per provider', function () {
    Log::spy();
    reconcileLinkedIn();
    reconcileLinkedIn(['provider_holder_id' => 'member-2'])->accounts()->update(['provider_holder_id' => 'member-2']);
    Http::fake(['api.linkedin.com/*' => Http::response(['message' => 'Internal error'], 500)]);

    $this->artisan('social-tokens:check-static')->assertSuccessful();

    expect(SocialAccount::where('status', AccountStatus::Active->value)->count())->toBe(6);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => str_contains($message, 'could not list the accounts behind 2 credential(s) of linkedin accounts'))->once();
});

/** A LinkedIn member ($member-token) and the organizations they connected. */
function reconcileMember(string $member, array $organizations): SocialToken
{
    $token = SocialToken::create([
        'provider' => 'linkedin', 'provider_holder_id' => $member, 'access_token' => "{$member}-token", 'status' => AccountStatus::Active,
        'expires_at' => now()->addDays(30), 'renew_at' => now()->addDays(25),
    ]);

    foreach ($organizations as $id) {
        SocialAccount::create(['provider' => 'linkedin', 'provider_user_id' => (string) $id, 'provider_holder_id' => $member, 'social_token_id' => $token->getKey(), 'status' => AccountStatus::Active]);
    }

    return $token;
}

/**
 * LinkedIn lists, per member token, these organizations; null fails the list.
 *
 * @param  array<string, array<int, string>|null>  $byMember
 */
function reconcileOrganizationsByMember(array $byMember): void
{
    Http::fake(['api.linkedin.com/v2/organizationAcls*' => function (Request $request) use ($byMember) {
        $member = Str::between((string) ($request->header('Authorization')[0] ?? ''), 'Bearer ', '-token');
        $organizations = array_key_exists($member, $byMember) ? $byMember[$member] : [];

        return $organizations === null
            ? Http::response(['message' => 'Internal error'], 500)
            : Http::response(['elements' => array_map(fn (string $id) => [
                'role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'organization' => "urn:li:organization:{$id}", 'organization~' => ['id' => (int) $id, 'localizedName' => "Org {$id}"],
            ], $organizations)]);
    }]);
}

it('flags nothing when too many holders lose accounts at once', function () {
    Log::spy();
    config(['social-tokens.check_static_breaker' => ['min_checked' => 2, 'max_terminal_ratio' => 0.2]]);
    reconcileMember('m1', ['11']);
    reconcileMember('m2', ['21']);
    reconcileMember('m3', ['31']);
    reconcileOrganizationsByMember(['m1' => [], 'm2' => [], 'm3' => []]);

    $this->artisan('social-tokens:check-static')
        ->expectsOutputToContain('Aborted reconciling linkedin: 0 of 3 lists failed and 3 holders lost accounts at once')
        ->assertFailed();

    expect(SocialAccount::where('status', AccountStatus::Active->value)->count())->toBe(3);
    Log::shouldHaveReceived('critical')->withArgs(fn (string $message) => str_contains($message, 'skipped reconciling linkedin'))->once();
});

/*
 * The breaker counts holders, not accounts: an agency member who loses fifteen
 * organizations at once is one holder. Counted by accounts, that one loss
 * tripped the breaker every day and held up everyone else's.
 */
it('flags the accounts of one holder who lost many at once', function () {
    config(['social-tokens.check_static_breaker' => ['min_checked' => 2, 'max_terminal_ratio' => 0.2]]);
    $agency = array_map(fn (int $i) => (string) (100 + $i), range(1, 15));
    reconcileMember('agency', $agency);

    foreach (range(1, 5) as $i) {
        reconcileMember("m{$i}", ["{$i}0"]);
    }

    reconcileOrganizationsByMember(['agency' => []] + collect(range(1, 5))->mapWithKeys(fn (int $i) => ["m{$i}" => ["{$i}0"]])->all());

    $this->artisan('social-tokens:check-static')
        ->expectsOutputToContain('Checked 20 account(s) behind 6 shared credential(s), flagged 15.')
        ->assertSuccessful();

    expect(SocialAccount::where('provider_holder_id', 'agency')->where('status', AccountStatus::NeedsReconnect->value)->count())->toBe(15);
});

it('flags nothing when too many lists fail at once, short ones getting through', function () {
    config(['social-tokens.check_static_breaker' => ['min_checked' => 2, 'max_terminal_ratio' => 0.2]]);
    reconcileMember('m1', ['11']);
    reconcileMember('m2', ['21']);
    reconcileMember('m3', ['31']);
    reconcileOrganizationsByMember(['m1' => null, 'm2' => null, 'm3' => []]);

    $this->artisan('social-tokens:check-static')
        ->expectsOutputToContain('Aborted reconciling linkedin: 2 of 3 lists failed')
        ->assertFailed();

    expect(SocialAccount::where('status', AccountStatus::Active->value)->count())->toBe(3);
});

it('does not reconcile a provider the token checks found in an incident', function () {
    config([
        'social-tokens.check_renewable' => true,
        'social-tokens.check_static_breaker' => ['min_checked' => 2, 'max_terminal_ratio' => 0.2],
    ]);
    reconcileMember('m1', ['11']);
    reconcileMember('m2', ['21']);

    // Healthy Facebook Pages: the run as a whole is below the breaker, only
    // LinkedIn's own breaker trips.
    foreach (range(1, 9) as $i) {
        $page = SocialToken::create(['provider' => 'facebook', 'provider_holder_id' => "page-{$i}", 'access_token' => "page-token-{$i}", 'status' => AccountStatus::Active]);
        SocialAccount::create(['provider' => 'facebook', 'provider_user_id' => "page-{$i}", 'provider_holder_id' => 'fb-user', 'social_token_id' => $page->getKey(), 'status' => AccountStatus::Active]);
    }

    Http::fake([
        'www.linkedin.com/oauth/v2/introspectToken' => Http::response(['active' => false, 'status' => 'revoked']),
        'api.linkedin.com/*' => Http::response(['elements' => []]),
        'graph.facebook.com/*' => Http::response(['id' => 'page']),
    ]);

    $this->artisan('social-tokens:check-static')
        ->expectsOutputToContain('Aborted for linkedin')
        ->assertFailed();

    expect(SocialAccount::where('provider', 'linkedin')->where('status', AccountStatus::Active->value)->count())->toBe(2);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'organizationAcls'));
});

it('never judges the holder\'s own profile, even on a row without a holder', function () {
    $token = reconcileMember('member-1', ['111']);
    SocialAccount::create(['provider' => 'linkedin', 'provider_user_id' => 'member-1', 'provider_holder_id' => null, 'social_token_id' => $token->getKey(), 'status' => AccountStatus::Active]);
    reconcileOrganizationsByMember(['member-1' => ['111']]);

    $this->artisan('social-tokens:check-static')->expectsOutputToContain('Checked 1 account(s) behind 1 shared credential(s), flagged 0.');

    expect(reconcileAccount('linkedin', 'member-1')->status)->toBe(AccountStatus::Active);
});

it('does not flag on a list read with a token a reconnect replaced during the run', function () {
    $token = reconcileLinkedIn();
    $calls = 0;

    Http::fake(['api.linkedin.com/v2/organizationAcls*' => function () use (&$calls, $token) {
        if (++$calls === 2) {
            // The member, an admin again, reconnects: same credential, new token
            $token->fresh()->forceFill(['access_token' => 'reconnected-token'])->save();
        }

        return Http::response(['elements' => [['role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'organization' => 'urn:li:organization:111', 'organization~' => ['id' => 111, 'localizedName' => 'Org 111']]]]);
    }]);

    $this->artisan('social-tokens:check-static')->assertSuccessful();

    expect(reconcileAccount('linkedin', '222')->status)->toBe(AccountStatus::Active);
});

it('brings back at reconnect an Instagram account flagged as no longer managed that the token reaches', function () {
    Event::fake([AccountConnected::class]);
    $token = reconcileInstagram();
    reconcileAccount('instagram', 'ig-2')->forceFill(['status' => AccountStatus::NeedsReconnect, 'last_error' => 'Instagram account no longer managed by the connected Facebook user.'])->save();
    reconcileInstagramGraph(['id' => 'ig-2']);
    Http::fake(['graph.facebook.com/*/me*' => Http::response(['id' => 'fb-user-1'])]);

    $accounts = app(StoreInstagramAccounts::class)->handle(userToken: 'user-token', userId: 'fb-user-1', extend: false);

    expect(reconcileAccount('instagram', 'ig-2')->status)->toBe(AccountStatus::Active)
        ->and(reconcileAccount('instagram', 'ig-2')->last_error)->toBeNull()
        ->and(reconcileAccount('instagram', 'ig-2')->social_token_id)->toBe($token->getKey())
        ->and($accounts->pluck('provider_user_id'))->toContain('ig-2');

    Event::assertDispatched(AccountConnected::class, fn ($event) => $event->account->provider_user_id === 'ig-2');
});

it('leaves an account flagged for another reason as it is at reconnect', function () {
    reconcileInstagram();
    reconcileAccount('instagram', 'ig-2')->forceFill(['status' => AccountStatus::NeedsReconnect, 'last_error' => 'Missing permission'])->save();
    reconcileInstagramGraph(['id' => 'ig-2']);
    Http::fake(['graph.facebook.com/*/me*' => Http::response(['id' => 'fb-user-1'])]);

    app(StoreInstagramAccounts::class)->handle(userToken: 'user-token', userId: 'fb-user-1', extend: false);

    expect(reconcileAccount('instagram', 'ig-2')->status)->toBe(AccountStatus::NeedsReconnect);
});

it('leaves a token it cannot use now to the renewal', function (array $credential) {
    $token = reconcileLinkedIn($credential);

    if (($credential['access_token'] ?? null) === 'undecryptable') {
        SocialToken::query()->whereKey($token->getKey())->update(['access_token' => 'not-a-ciphertext']);
    }

    Http::fake();

    $this->artisan('social-tokens:check-static')->assertSuccessful();

    Http::assertNothingSent();
    expect(SocialAccount::where('status', AccountStatus::Active->value)->count())->toBe(3);
})->with([
    'expired' => [['expires_at' => now()->subHour(), 'renew_at' => now()->subDays(5)]],
    'undecryptable' => [['access_token' => 'undecryptable']],
]);

it('only judges accounts still connected', function () {
    reconcileLinkedIn();
    reconcileAccount('linkedin', '222')->forceFill(['status' => AccountStatus::Revoked, 'disconnected_at' => now()])->save();
    reconcileOrganizations(['111']);

    $this->artisan('social-tokens:check-static')->expectsOutputToContain('Checked 1 account(s) behind 1 shared credential(s), flagged 0.');

    expect(reconcileAccount('linkedin', '222')->status)->toBe(AccountStatus::Revoked);
});

it('does not flag an account a reconnect moved to another credential during the check', function () {
    $token = reconcileLinkedIn();
    $other = SocialToken::create(['provider' => 'linkedin', 'provider_holder_id' => 'member-1', 'purpose' => 'organizations', 'access_token' => 'new-token', 'status' => AccountStatus::Active]);
    $calls = 0;

    Http::fake(['api.linkedin.com/v2/organizationAcls*' => function (Request $request) use (&$calls, $other) {
        // The new token (the reconnect's) lists 222; the old one no longer does.
        $ids = $request->hasHeader('Authorization', 'Bearer new-token') ? [111, 222] : [111];

        if (++$calls === 2) {
            reconcileAccount('linkedin', '222')->forceFill(['social_token_id' => $other->getKey()])->save();
        }

        return Http::response(['elements' => array_map(fn (int $id) => [
            'role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'organization' => "urn:li:organization:{$id}", 'organization~' => ['id' => $id, 'localizedName' => "Org {$id}"],
        ], $ids)]);
    }]);

    $this->artisan('social-tokens:check-static')->assertSuccessful();

    expect(reconcileAccount('linkedin', '222')->status)->toBe(AccountStatus::Active);
});

it('can be turned off', function () {
    config(['social-tokens.reconcile_accounts' => false]);
    reconcileLinkedIn();
    Http::fake();

    $this->artisan('social-tokens:check-static')->assertSuccessful();

    Http::assertNothingSent();
});
