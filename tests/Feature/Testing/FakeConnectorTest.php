<?php

use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Log;
use Pr4w\SocialTokens\Actions\StoreConnection;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Exceptions\NeedsReconnectException;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\RenewalResult;
use Pr4w\SocialTokens\Testing\FakeConnector;

beforeEach(function () {
    // Before anything resolves the connector registry, which reads the config once.
    config()->set('social-tokens.connectors.acme', ['driver' => FakeConnector::class]);
    config()->set('social-tokens.connectors.acme_reauth', [
        'driver' => FakeConnector::class,
        'strategy' => 'reauth_only',
        'lead_minutes' => 60,
    ]);

    $this->acme = app(SocialTokens::class)->connector('acme');
});

function acmeAccount(): SocialAccount
{
    return SocialAccount::factory()->forProvider('acme')->credential(fn ($credential) => $credential->expired())->create();
}

it('is the instance the package renews through, and renews without a request', function () {
    expect($this->acme)->toBeInstanceOf(FakeConnector::class)
        ->and($this->acme->renewalStrategy())->toBe(RenewalStrategy::StableRefreshToken)
        ->and($this->acme->leadTime()->totalMinutes)->toEqual(15);

    $account = acmeAccount();

    expect(app(SocialTokens::class)->validAccessTokenFor($account))->toBe(FakeConnector::RENEWED_TOKEN);
    $this->acme->assertRenewed($account);
    $this->acme->assertRenewed(times: 1);
});

it('answers renewals as told', function () {
    $this->acme->renewWith(RenewalResult::terminalFailure('invalid_grant'));

    $account = acmeAccount();

    expect(fn () => app(SocialTokens::class)->validAccessTokenFor($account))
        ->toThrow(fn (NeedsReconnectException $e) => expect($e->transient)->toBeFalse())
        ->and($account->credential->fresh()->status)->toBe(AccountStatus::NeedsReconnect);
});

it('takes its strategy and lead time from its config block or the instance', function () {
    $reauth = app(SocialTokens::class)->connector('acme_reauth');

    expect($reauth->renewalStrategy())->toBe(RenewalStrategy::ReauthOnly)
        ->and($reauth->leadTime()->totalMinutes)->toEqual(60);

    $this->acme->withStrategy(RenewalStrategy::ReauthOnly)->withLeadTime(CarbonInterval::days(7));

    expect($this->acme->renewalStrategy())->toBe(RenewalStrategy::ReauthOnly)
        ->and($this->acme->leadTime()->totalDays)->toEqual(7);

    // A credential that cannot renew unattended is never sent to the provider.
    expect(fn () => app(SocialTokens::class)->validAccessTokenFor(acmeAccount()))->toThrow(NeedsReconnectException::class);
    $this->acme->assertNotRenewed();
});

it('hands a connection the long-lived token it is told to', function () {
    $this->acme->exchangeWith(RenewalResult::success(accessToken: 'long-lived', expiresAt: now()->addDays(60)));

    $account = app(StoreConnection::class)->handle('acme', socialiteUser(['token' => 'short-lived']))->sole();

    expect($account->credential->access_token)->toBe('long-lived')
        ->and($account->credential->expires_at->isSameDay(now()->addDays(60)))->toBeTrue();
});

it('records revocations, and runs the callback it is given', function () {
    Log::spy();
    $this->acme->revokeUsing(fn () => throw new RuntimeException('provider down'));

    $account = SocialAccount::factory()->forProvider('acme')->create();
    $token = $account->credential->access_token;

    app(SocialTokens::class)->disconnect($account);

    $this->acme->assertRevokedAtProvider($account->credential);
    expect($this->acme->revocations()[0]->access_token)->toBe($token)
        ->and($account->credential->fresh()->status)->toBe(AccountStatus::Revoked);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'revocation failed'))->once();
});
