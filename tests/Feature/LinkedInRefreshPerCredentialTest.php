<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Actions\StoreAccountFromSocialite;
use Pr4w\SocialTokens\Actions\StoreConnection;
use Pr4w\SocialTokens\Connectors\LinkedInConnector;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Events\CredentialExpiringSoon;
use Pr4w\SocialTokens\Events\CredentialNeedsReconnect;
use Pr4w\SocialTokens\Exceptions\NeedsReconnectException;
use Pr4w\SocialTokens\Jobs\RenewCredential;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

/*
 * LinkedIn issues refresh tokens per app (programmatic refresh tokens), but
 * `refresh_enabled` switched the strategy for every LinkedIn credential. A
 * credential stored without a refresh token (an app without them, a manual
 * exchange that left it out, a row from before the switch) was sent to the
 * refresh call when its window opened, got "No refresh token" and was flagged
 * five days before its token expired, with no CredentialExpiringSoon: the
 * early cut-off 1.1 removed for ReauthOnly. The strategy is now decided per
 * credential.
 */

beforeEach(function () {
    config()->set('social-tokens.connectors.linkedin.refresh_enabled', true);
    app()->forgetInstance(ConnectorRegistry::class);
    app()->forgetInstance(SocialTokens::class);

    Http::preventStrayRequests();
});

/** A LinkedIn profile connected without a refresh token: a 60-day token. */
function perCredentialLinkedIn(?string $refreshToken = null, ?int $expiresIn = 60 * 86400): SocialAccount
{
    return app(StoreAccountFromSocialite::class)->handle('linkedin', socialiteUser([
        'id' => 'member-1',
        'token' => 'member-access',
        'refreshToken' => $refreshToken,
        'expiresIn' => $expiresIn ?? 0, // LinkedIn left out expires_in
    ]));
}

function perCredentialRunJob(SocialToken $token): void
{
    (new RenewCredential($token))->handle(app(SocialTokens::class), app(ConnectorRegistry::class));
}

it('decides the LinkedIn strategy per credential', function () {
    $connector = new LinkedInConnector(['refresh_enabled' => true]);

    expect($connector->renewalStrategyFor(new SocialToken(['refresh_token' => 'r'])))->toBe(RenewalStrategy::StableRefreshToken)
        ->and($connector->renewalStrategyFor(new SocialToken))->toBe(RenewalStrategy::ReauthOnly)
        ->and((new LinkedInConnector(['refresh_enabled' => false]))->renewalStrategyFor(new SocialToken(['refresh_token' => 'r'])))->toBe(RenewalStrategy::ReauthOnly);
});

it('warns ahead of the expiry instead of flagging a credential without a refresh token', function () {
    Http::fake();
    Event::fake([CredentialExpiringSoon::class, CredentialNeedsReconnect::class]);
    $credential = perCredentialLinkedIn()->credential;

    $this->travelTo($credential->renew_at->copy()->addMinute());
    perCredentialRunJob($credential);

    $credential->refresh();

    expect($credential->status)->toBe(AccountStatus::Active)
        ->and($credential->renew_at->equalTo($credential->expires_at))->toBeTrue();

    Event::assertDispatched(CredentialExpiringSoon::class, fn (CredentialExpiringSoon $event) => $event->expiresAt->equalTo($credential->expires_at));
    Event::assertNotDispatched(CredentialNeedsReconnect::class);
    Http::assertNothingSent();
});

it('flags a credential without a refresh token once it has expired', function () {
    Http::fake();
    $credential = perCredentialLinkedIn()->credential;

    $this->travelTo($credential->renew_at->copy()->addMinute());
    perCredentialRunJob($credential);

    $this->travelTo($credential->fresh()->expires_at->copy()->addMinute());
    perCredentialRunJob($credential);

    expect($credential->fresh()->status)->toBe(AccountStatus::NeedsReconnect);
    Http::assertNothingSent();
});

it('still refreshes a LinkedIn credential that holds a refresh token', function () {
    Http::fake(['www.linkedin.com/oauth/v2/accessToken' => Http::response([
        'access_token' => 'member-access-2',
        'expires_in' => 60 * 86400,
    ])]);
    $credential = perCredentialLinkedIn('member-refresh')->credential;

    $this->travelTo($credential->renew_at->copy()->addMinute());
    perCredentialRunJob($credential);

    expect($credential->fresh()->access_token)->toBe('member-access-2');
});

it('hands out the token until it expires, then flags it without calling LinkedIn', function () {
    Http::fake();
    $account = perCredentialLinkedIn();
    $tokens = app(SocialTokens::class);

    $this->travelTo($account->credential->renew_at->copy()->addMinute());
    expect($tokens->validAccessTokenFor($account))->toBe('member-access');

    $this->travelTo($account->credential->expires_at->copy()->addMinute());

    expect(fn () => $tokens->validAccessTokenFor($account))->toThrow(NeedsReconnectException::class)
        ->and($account->credential->fresh()->status)->toBe(AccountStatus::NeedsReconnect);
    Http::assertNothingSent();
});

it('flags a credential without a refresh token when its renewal ran out of retries after it expired', function () {
    $credential = perCredentialLinkedIn()->credential;

    $this->travelTo($credential->expires_at->copy()->addMinute());
    (new RenewCredential($credential))->failed(new RuntimeException('Provider returned HTTP 503'));

    expect($credential->fresh()->status)->toBe(AccountStatus::NeedsReconnect);
});

it('leaves a credential without a refresh token nor a known expiry unscheduled', function () {
    // Checked back after one lead time, it would be flagged at once: nothing
    // can renew it, and there is no expiry to warn about.
    $credential = perCredentialLinkedIn(expiresIn: null)->credential;

    expect($credential->renew_at)->toBeNull()
        ->and(perCredentialLinkedIn('member-refresh', expiresIn: null)->credential->renew_at)->not->toBeNull();
});

it('takes LinkedIn answering expired as definitive for a credential without a refresh token', function () {
    Http::fake(['www.linkedin.com/oauth/v2/introspectToken' => Http::response(['active' => false, 'status' => 'expired'])]);
    $credential = perCredentialLinkedIn()->credential;

    $result = app(SocialTokens::class)->connector('linkedin')->checkCredential($credential);

    expect($result->outcome)->toBe(RenewalOutcome::Terminal)
        ->and($result->context['definitive'] ?? false)->toBeTrue();
});

it('stores a refresh token expiry the app passes, which core Socialite does not expose', function () {
    $refreshExpiresAt = now()->addDays(365);

    $account = app(StoreAccountFromSocialite::class)->handle('linkedin', socialiteUser([
        'id' => 'member-1',
        'refreshToken' => 'member-refresh',
        'expiresIn' => 60 * 86400,
    ]), refreshExpiresAt: $refreshExpiresAt);

    $viaConnection = app(StoreConnection::class)->handle('linkedin', socialiteUser([
        'id' => 'member-2',
        'refreshToken' => 'member-refresh-2',
        'expiresIn' => 60 * 86400,
    ]), refreshExpiresAt: $refreshExpiresAt)->sole();

    expect($account->credential->refresh_expires_at->timestamp)->toBe($refreshExpiresAt->timestamp)
        ->and($viaConnection->credential->refresh_expires_at->timestamp)->toBe($refreshExpiresAt->timestamp);
});

it('builds core Socialite users without a token response body', function () {
    // Only SocialiteProviders' user declares accessTokenResponseBody; setting
    // it on a core user is a deprecated dynamic property (an Error in PHP 9).
    expect(property_exists(socialiteUser(), 'accessTokenResponseBody'))->toBeFalse()
        ->and(socialiteUser(['accessTokenResponseBody' => ['refresh_expires_in' => 1]])->accessTokenResponseBody)
        ->toBe(['refresh_expires_in' => 1]);
});
