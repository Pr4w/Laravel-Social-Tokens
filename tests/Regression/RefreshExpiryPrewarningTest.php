<?php

/*
 * Regression: warn BEFORE a refresh token dies, and never forget a known
 * refresh token or its expiry.
 *
 * LinkedIn refresh tokens (Community Management / MDP) live 365 days and are
 * NOT extended on use, so the member has to re-authorise once a year whatever
 * the package does. In v1.1.0:
 *
 *  - Nothing warns while refresh_expires_at approaches. The dispatcher selects
 *    credentials on renew_at (= expires_at - lead) only, and RenewCredential
 *    reacts to the refresh token only once isRefreshTokenExpired() is already
 *    true. With an access token that outlives the refresh token, the single
 *    CredentialExpiringSoon arrives a lead time before the ACCESS token dies,
 *    not before the refresh token does. With an access token capped at the
 *    refresh token expiry, there is no warning at all: the job refreshes on
 *    every pass (the new token never moves renew_at into the future), then
 *    flags the credential.
 *  - StoreAccountFromSocialite and StoreLinkedInOrganizations write
 *    refresh_token and refresh_expires_at unconditionally on the shared
 *    (linkedin, member id) credential, so storing the personal profile (or
 *    re-syncing the organizations) without them wipes known values. And
 *    StoreAccountFromSocialite reads only TikTok's refresh_expires_in, not
 *    LinkedIn's refresh_token_expires_in.
 *
 * Desired: one CredentialExpiringSoon as soon as refresh_expires_at - lead <= now
 * (deduplicated over the credential's whole end of life, re-armed by a
 * reconnect), the credential keeps posting until its access token really dies,
 * and a known refresh token / refresh_expires_at is never replaced by null.
 *
 * Self-contained: uses only tests/Pest.php (socialiteUser), the TestCase, and
 * the package's own actions, command and events. The scheduler is driven for
 * real (social-tokens:dispatch-renewals on the sync queue).
 */

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Actions\StoreAccountFromSocialite;
use Pr4w\SocialTokens\Actions\StoreLinkedInOrganizations;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\CredentialExpiringSoon;
use Pr4w\SocialTokens\Events\CredentialNeedsReconnect;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

/**
 * Fake LinkedIn: the organizationAcls listing (one administered organization)
 * and a token endpoint that honours each refresh token's own expiry (LinkedIn
 * does not extend it on use). With $capped, a refreshed access token never
 * outlives the refresh token; otherwise it always lives 60 days.
 *
 * @param  array<string, CarbonInterface>  $refreshTokens  refresh token => its expiry
 */
function refreshPrewarnFakeLinkedIn(array $refreshTokens, bool $capped = false): void
{
    Http::fake([
        'api.linkedin.com/v2/organizationAcls*' => Http::response(['elements' => [[
            'role' => 'ADMINISTRATOR',
            'state' => 'APPROVED',
            'organization~' => ['id' => '111', 'localizedName' => 'Acme'],
        ]]]),
        'www.linkedin.com/oauth/v2/accessToken' => function (Request $request) use ($refreshTokens, $capped) {
            $refreshExpiresAt = $refreshTokens[$request['refresh_token']] ?? null;

            if ($refreshExpiresAt === null || ! now()->lessThan($refreshExpiresAt)) {
                return Http::response([
                    'error' => 'invalid_grant',
                    'error_description' => 'The provided authorization grant or refresh token is invalid, expired or revoked.',
                ], 400);
            }

            $remaining = (int) now()->diffInSeconds($refreshExpiresAt, true);

            return Http::response([
                'access_token' => 'member-access-'.now()->timestamp,
                'expires_in' => $capped ? min(60 * 86400, $remaining) : 60 * 86400,
                'refresh_token' => $request['refresh_token'],
                'refresh_token_expires_in' => $remaining,
            ]);
        },
    ]);
}

/** Connect (or reconnect) member-1's organizations and return the shared credential. */
function refreshPrewarnConnect(string $refreshToken, CarbonInterface $expiresAt, CarbonInterface $refreshExpiresAt): SocialToken
{
    app(StoreLinkedInOrganizations::class)->handle(
        accessToken: 'member-access',
        memberId: 'member-1',
        refreshToken: $refreshToken,
        expiresAt: $expiresAt,
        refreshExpiresAt: $refreshExpiresAt,
    );

    return SocialToken::query()
        ->where('provider', 'linkedin')
        ->where('provider_holder_id', 'member-1')
        ->sole();
}

/**
 * How long before refresh_expires_at the warning is due: the provider's lead
 * time, as for every other CredentialExpiringSoon (5 days for LinkedIn). If the
 * fix introduces a dedicated, longer warning lead, point this helper at it.
 */
function refreshPrewarnLead(): CarbonInterval
{
    return app(SocialTokens::class)->connector('linkedin')->leadTime();
}

/** One scheduler pass at $at: the dispatcher queues due renewals, the sync queue runs them. */
function refreshPrewarnTickAt(CarbonInterface $at): void
{
    test()->travelTo($at);

    expect(Artisan::call('social-tokens:dispatch-renewals'))->toBe(0);
}

/** Run the scheduler every $every from $from to $until, until the credential is no longer active. */
function refreshPrewarnRunScheduler(SocialToken $credential, CarbonInterface $from, CarbonInterface $until, CarbonInterval $every): void
{
    for ($at = CarbonImmutable::instance($from); $at->lessThanOrEqualTo($until); $at = $at->add($every)) {
        refreshPrewarnTickAt($at);

        if ($credential->fresh()->status !== AccountStatus::Active) {
            return;
        }
    }
}

beforeEach(function () {
    config()->set('social-tokens.connectors.linkedin.refresh_enabled', true);
    app()->forgetInstance(ConnectorRegistry::class);
    app()->forgetInstance(SocialTokens::class);

    // Run RenewCredential inline, the way a worker would pick it up right away,
    // and never let a request reach the real LinkedIn.
    config()->set('queue.default', 'sync');
    Http::preventStrayRequests();

    $this->travelTo(CarbonImmutable::parse('2026-01-05 09:00:00'));

    // Record when each event fires, on the real clock the scheduler runs on.
    $this->warnings = new Collection;
    $this->reconnects = new Collection;

    Event::listen(CredentialExpiringSoon::class, fn (CredentialExpiringSoon $event) => $this->warnings->push([
        'at' => now()->toImmutable(),
        'token' => $event->token->getKey(),
        'expiresAt' => CarbonImmutable::instance($event->expiresAt),
    ]));

    Event::listen(CredentialNeedsReconnect::class, fn (CredentialNeedsReconnect $event) => $this->reconnects->push([
        'at' => now()->toImmutable(),
        'reason' => $event->reason,
    ]));
});

// Warning ahead of refresh_expires_at -----------------------------------------

it('warns once, a lead time ahead, when a LinkedIn refresh token nears its expiry', function () {
    // The last year of a member token: the access token (60 days) outlives the
    // refresh token, which LinkedIn never extends.
    $refreshExpiresAt = now()->addDays(30)->toImmutable();
    $expiresAt = now()->addDays(60)->toImmutable();
    $warnAt = $refreshExpiresAt->sub(refreshPrewarnLead());

    refreshPrewarnFakeLinkedIn(['member-refresh' => $refreshExpiresAt]);
    $credential = refreshPrewarnConnect('member-refresh', $expiresAt, $refreshExpiresAt);

    refreshPrewarnTickAt($warnAt->subMinute());

    expect($this->warnings)->toBeEmpty();

    refreshPrewarnTickAt($warnAt->addMinute());

    expect($this->warnings)->toHaveCount(1, 'CredentialExpiringSoon must fire once refresh_expires_at - lead <= now');

    $warning = $this->warnings->first();

    // The deadline announced is no earlier than the refresh token's own expiry
    // and no later than the moment the credential really stops working: the
    // expiry of its current access token (which a last refresh may extend).
    $stopsWorkingAt = $credential->fresh()->expires_at;

    expect($warning['token'])->toBe($credential->getKey())
        ->and($warning['expiresAt']->greaterThanOrEqualTo($refreshExpiresAt))->toBeTrue()
        ->and($stopsWorkingAt?->greaterThanOrEqualTo($expiresAt))->toBeTrue()
        ->and($warning['expiresAt']->lessThanOrEqualTo($stopsWorkingAt))->toBeTrue();

    // Nothing is cut off early: the organization still posts.
    $account = SocialAccount::query()->where('provider_user_id', '111')->sole();

    expect(app(SocialTokens::class)->validAccessTokenFor($account))->toBeString()
        ->and($credential->fresh()->status)->toBe(AccountStatus::Active);

    // Later passes before the refresh token dies do not warn again.
    refreshPrewarnTickAt($warnAt->addMinutes(16));
    refreshPrewarnTickAt($warnAt->addDay());
    refreshPrewarnTickAt($refreshExpiresAt->subMinute());

    expect($this->warnings)->toHaveCount(1)
        ->and($this->reconnects)->toBeEmpty();
});

it('sends a single early warning over the whole end of life of a LinkedIn refresh token', function () {
    $refreshExpiresAt = now()->addDays(30)->toImmutable();
    $expiresAt = now()->addDays(60)->toImmutable();
    $warnAt = $refreshExpiresAt->sub(refreshPrewarnLead());

    refreshPrewarnFakeLinkedIn(['member-refresh' => $refreshExpiresAt]);
    $credential = refreshPrewarnConnect('member-refresh', $expiresAt, $refreshExpiresAt);

    // Cron every 6 hours until the credential is flagged.
    refreshPrewarnRunScheduler($credential, now()->addMinute(), now()->addDays(120), CarbonInterval::hours(6));

    $warnedInTime = $this->warnings->filter(fn (array $warning) => $warning['at']->lessThanOrEqualTo($warnAt->addHours(6)));

    expect($warnedInTime)->toHaveCount(1, 'the warning must come a lead time before refresh_expires_at, not before expires_at')
        ->and($this->warnings)->toHaveCount(1, 'one warning for the whole end of life, not one more when the refresh token has died')
        ->and($this->reconnects)->toHaveCount(1)
        // Still usable until the access token really dies.
        ->and($this->reconnects->first()['at']->greaterThanOrEqualTo($expiresAt->subMinute()))->toBeTrue()
        ->and($credential->fresh()->status)->toBe(AccountStatus::NeedsReconnect);
});

it('warns once instead of refreshing on every pass when the access token is capped at the refresh token expiry', function () {
    // After the last refresh LinkedIn issued an access token that dies with the
    // refresh token: renew_at (expires_at - lead) is also the warning point,
    // and no refresh can move it into the future any more.
    $refreshExpiresAt = now()->addDays(30)->toImmutable();
    $warnAt = $refreshExpiresAt->sub(refreshPrewarnLead());

    refreshPrewarnFakeLinkedIn(['member-refresh' => $refreshExpiresAt], capped: true);
    $credential = refreshPrewarnConnect('member-refresh', $refreshExpiresAt, $refreshExpiresAt);

    refreshPrewarnRunScheduler($credential, now()->addMinute(), now()->addDays(40), CarbonInterval::hours(6));

    $warnedInTime = $this->warnings->filter(fn (array $warning) => $warning['at']->lessThanOrEqualTo($warnAt->addHours(6)));
    $refreshCalls = Http::recorded(fn (Request $request) => str_contains($request->url(), 'oauth/v2/accessToken'))->count();

    expect($warnedInTime)->toHaveCount(1, 'CredentialExpiringSoon must fire once refresh_expires_at - lead <= now')
        ->and($this->warnings)->toHaveCount(1, 'one warning, even if a renewal also finds the token cannot be extended')
        ->and($refreshCalls)->toBeLessThanOrEqual(1, 'a refresh cannot outlive the refresh token: do not call LinkedIn on every pass')
        ->and($this->reconnects)->toHaveCount(1)
        ->and($this->reconnects->first()['at']->greaterThanOrEqualTo($refreshExpiresAt->subMinute()))->toBeTrue();
});

it('warns again about the new refresh token after the member reconnects', function () {
    $firstExpiry = now()->addDays(30)->toImmutable();
    $reconnectAt = $firstExpiry->subDays(2);
    $secondExpiry = $reconnectAt->addDays(40);

    refreshPrewarnFakeLinkedIn(['member-refresh' => $firstExpiry, 'member-refresh-2' => $secondExpiry]);
    $credential = refreshPrewarnConnect('member-refresh', now()->addDays(60), $firstExpiry);

    refreshPrewarnTickAt($firstExpiry->sub(refreshPrewarnLead())->addMinute());

    expect($this->warnings)->toHaveCount(1, 'CredentialExpiringSoon must fire once refresh_expires_at - lead <= now');

    // The member re-authorises: a new refresh token with its own expiry.
    $this->travelTo($reconnectAt);
    refreshPrewarnConnect('member-refresh-2', $reconnectAt->addDays(60), $secondExpiry);

    refreshPrewarnTickAt($firstExpiry->addMinute());
    refreshPrewarnTickAt($secondExpiry->sub(refreshPrewarnLead())->subMinute());

    expect($this->warnings)->toHaveCount(1);

    refreshPrewarnTickAt($secondExpiry->sub(refreshPrewarnLead())->addMinute());

    expect($this->warnings)->toHaveCount(2, 'the dedup must be per refresh token expiry, re-armed by a reconnect')
        ->and($this->warnings->last()['token'])->toBe($credential->getKey())
        ->and($this->warnings->last()['expiresAt']->greaterThanOrEqualTo($secondExpiry))->toBeTrue()
        ->and($this->reconnects)->toBeEmpty();
});

// Never forget a known refresh token or its expiry ---------------------------

it('keeps the known refresh token expiry when the personal LinkedIn profile is stored after the organizations', function () {
    $refreshExpiresAt = now()->addDays(365)->toImmutable();
    refreshPrewarnFakeLinkedIn([]);

    refreshPrewarnConnect('member-refresh', now()->addDays(60), $refreshExpiresAt);

    // Same OAuth exchange, personal row: core Socialite exposes no token
    // response body, so the action has no refresh expiry to offer.
    app(StoreAccountFromSocialite::class)->handle('linkedin', socialiteUser([
        'id' => 'member-1',
        'token' => 'member-access',
        'refreshToken' => 'member-refresh',
        'expiresIn' => 60 * 86400,
    ]));

    $credential = SocialToken::query()->sole();

    expect($credential->refresh_expires_at)->not->toBeNull('a known refresh_expires_at must never be replaced by null')
        ->and($credential->refresh_expires_at->equalTo($refreshExpiresAt))->toBeTrue()
        ->and($credential->refresh_token)->toBe('member-refresh');
});

it('keeps the shared LinkedIn refresh token when the personal profile is stored without one', function () {
    $refreshExpiresAt = now()->addDays(365)->toImmutable();
    refreshPrewarnFakeLinkedIn([]);

    refreshPrewarnConnect('member-refresh', now()->addDays(60), $refreshExpiresAt);

    app(StoreAccountFromSocialite::class)->handle('linkedin', socialiteUser([
        'id' => 'member-1',
        'token' => 'member-access',
        'expiresIn' => 60 * 86400,
    ]));

    $credential = SocialToken::query()->sole();

    expect($credential->refresh_token)->toBe('member-refresh', 'a known refresh_token must never be replaced by null')
        ->and($credential->refresh_expires_at?->equalTo($refreshExpiresAt))->toBeTrue();
});

it('keeps the refresh token and its expiry when the organizations are re-synced without them', function () {
    $refreshExpiresAt = now()->addDays(365)->toImmutable();
    refreshPrewarnFakeLinkedIn([]);

    refreshPrewarnConnect('member-refresh', now()->addDays(60), $refreshExpiresAt);

    // A later re-sync of the organization list with the current member token only.
    app(StoreLinkedInOrganizations::class)->handle(
        accessToken: 'member-access',
        memberId: 'member-1',
        expiresAt: now()->addDays(60),
    );

    $credential = SocialToken::query()->sole();

    expect($credential->refresh_token)->toBe('member-refresh', 'a known refresh_token must never be replaced by null')
        ->and($credential->refresh_expires_at)->not->toBeNull('a known refresh_expires_at must never be replaced by null')
        ->and($credential->refresh_expires_at->equalTo($refreshExpiresAt))->toBeTrue();
});

it('reads the refresh token expiry LinkedIn returns as refresh_token_expires_in', function () {
    app(StoreAccountFromSocialite::class)->handle('linkedin', socialiteUser([
        'id' => 'member-1',
        'token' => 'member-access',
        'refreshToken' => 'member-refresh',
        'expiresIn' => 60 * 86400,
        'accessTokenResponseBody' => [
            'access_token' => 'member-access',
            'expires_in' => 60 * 86400,
            'refresh_token' => 'member-refresh',
            'refresh_token_expires_in' => 365 * 86400,
        ],
    ]));

    $credential = SocialToken::query()->sole();

    expect($credential->refresh_expires_at)->not->toBeNull('refresh_token_expires_in (LinkedIn) must be read like refresh_expires_in (TikTok)')
        ->and($credential->refresh_expires_at->equalTo(now()->addDays(365)))->toBeTrue();
});

it('replaces the refresh token and its expiry when a reconnect brings new ones', function () {
    refreshPrewarnFakeLinkedIn([]);

    refreshPrewarnConnect('member-refresh', now()->addDays(60), now()->addDays(30));

    app(StoreAccountFromSocialite::class)->handle('linkedin', socialiteUser([
        'id' => 'member-1',
        'token' => 'member-access-2',
        'refreshToken' => 'member-refresh-2',
        'expiresIn' => 60 * 86400,
        'accessTokenResponseBody' => ['refresh_expires_in' => 365 * 86400],
    ]));

    $credential = SocialToken::query()->sole();

    expect($credential->refresh_token)->toBe('member-refresh-2')
        ->and($credential->refresh_expires_at?->equalTo(now()->addDays(365)))->toBeTrue();
});
