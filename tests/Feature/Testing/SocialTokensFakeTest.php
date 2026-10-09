<?php

use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\ExpectationFailedException;
use Pr4w\SocialTokens\Connectors\GoogleConnector;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\CredentialRenewed;
use Pr4w\SocialTokens\Exceptions\NeedsReconnectException;
use Pr4w\SocialTokens\Facades\SocialTokens;
use Pr4w\SocialTokens\Jobs\RenewCredential;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens as SocialTokensService;
use Pr4w\SocialTokens\Support\RenewalResult;
use Pr4w\SocialTokens\Testing\SocialTokensFake;
use Pr4w\SocialTokens\Tests\Fixtures\Owner;

// TestCase turns on Http::preventStrayRequests(): any provider call the fake
// failed to answer would throw.

it('resolves the service through the facade', function () {
    expect(SocialTokens::getFacadeRoot())->toBe(app(SocialTokensService::class))
        ->and(SocialTokens::connector('google'))->toBeInstanceOf(GoogleConnector::class);
});

it('swaps the service for the fake wherever it is resolved', function () {
    $fake = SocialTokens::fake();

    expect($fake)->toBeInstanceOf(SocialTokensFake::class)
        ->and(app(SocialTokensService::class))->toBe($fake)
        ->and(app('social-tokens'))->toBe($fake)
        ->and(SocialTokens::fake())->toBe($fake);
});

it('renews an expired credential without calling the provider', function () {
    Event::fake([CredentialRenewed::class]);
    SocialTokens::fake();

    $account = SocialAccount::factory()->forProvider('tiktok')->credential(fn ($credential) => $credential->expired())->create();

    expect(app(SocialTokensService::class)->validAccessTokenFor($account))->toBe(SocialTokensFake::RENEWED_TOKEN)
        ->and($account->credential->fresh()->access_token)->toBe(SocialTokensFake::RENEWED_TOKEN)
        ->and($account->credential->fresh()->renew_at->isFuture())->toBeTrue();

    SocialTokens::assertRenewed($account);
    SocialTokens::assertRenewed($account->credential, times: 1);
    Event::assertDispatched(CredentialRenewed::class);
});

it('does not renew a token that is still valid', function () {
    SocialTokens::fake();

    $account = SocialAccount::factory()->create();

    app(SocialTokensService::class)->validAccessTokenFor($account);

    SocialTokens::assertNotRenewed();
});

it('answers renewals as told, for every provider or for one', function () {
    $fake = SocialTokens::fake()
        ->renewWith(RenewalResult::transientFailure('Provider returned HTTP 503'))
        ->renewWith(RenewalResult::terminalFailure('invalid_grant'), provider: 'tiktok');

    $google = SocialAccount::factory()->credential(fn ($credential) => $credential->expired())->create();
    $tiktok = SocialAccount::factory()->forProvider('tiktok')->credential(fn ($credential) => $credential->expired())->create();

    expect(fn () => app(SocialTokensService::class)->validAccessTokenFor($google))
        ->toThrow(fn (NeedsReconnectException $e) => expect($e->transient)->toBeTrue())
        ->and(fn () => app(SocialTokensService::class)->validAccessTokenFor($tiktok))
        ->toThrow(fn (NeedsReconnectException $e) => expect($e->transient)->toBeFalse())
        ->and($google->credential->fresh()->status)->toBe(AccountStatus::Active)
        ->and($tiktok->credential->fresh()->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($tiktok->credential->fresh()->last_error)->toBe('invalid_grant')
        ->and($fake->renewals())->toHaveCount(2);
});

it('passes the credential to a scripted closure', function () {
    SocialTokens::fake()->renewWith(fn (SocialToken $token) => RenewalResult::success(
        accessToken: "renewed-{$token->provider_holder_id}",
        expiresAt: now()->addHours(2),
    ));

    $account = SocialAccount::factory()->forProvider('tiktok')->heldBy('open-id-1')
        ->credential(fn ($credential) => $credential->expired())
        ->create();

    expect(app(SocialTokensService::class)->validAccessTokenFor($account))->toBe('renewed-open-id-1');
});

it('answers the renewal job too', function () {
    SocialTokens::fake();

    $credential = SocialToken::factory()->forProvider('tiktok')->dueForRenewal()->create();
    SocialAccount::factory()->forProvider('tiktok')->for($credential, 'credential')->create();

    RenewCredential::dispatchSync($credential);

    SocialTokens::assertRenewed($credential);
    expect($credential->fresh()->access_token)->toBe(SocialTokensFake::RENEWED_TOKEN);
});

it('records a provider-side revocation instead of making it', function () {
    SocialTokens::fake();

    $account = SocialAccount::factory()->forProvider('tiktok')->create();
    $token = $account->credential->access_token;

    app(SocialTokensService::class)->disconnect($account);

    SocialTokens::assertRevokedAtProvider($account->credential);
    expect(SocialTokens::revocations()[0]->access_token)->toBe($token)
        ->and($account->credential->fresh()->status)->toBe(AccountStatus::Revoked);
});

it('makes no provider-side revocation while another owner holds a grant, as in production', function () {
    SocialTokens::fake();

    $account = SocialAccount::factory()->forProvider('tiktok')->heldBy('open-id-1')->ownedBy(Owner::create(['name' => 'a']))->create();
    SocialAccount::factory()->forProvider('tiktok')->heldBy('open-id-1')->ownedBy(Owner::create(['name' => 'b']))->create();

    app(SocialTokensService::class)->disconnect($account);

    SocialTokens::assertNotRevokedAtProvider();
    expect($account->credential->fresh()->status)->toBe(AccountStatus::Revoked);
});

it('answers the check of a rejection report', function () {
    $fake = SocialTokens::fake();

    $page = SocialAccount::factory()->forProvider('facebook')->create();

    expect(app(SocialTokensService::class)->reportRejected($page, 'Error validating access token', terminal: false))->toBeFalse();
    SocialTokens::assertChecked($page);

    $fake->checkWith(RenewalResult::terminalFailure('190/460 OAuthException: password changed', ['definitive' => true]));

    expect(app(SocialTokensService::class)->reportRejected($page, 'Error validating access token', terminal: false))->toBeTrue()
        ->and($page->credential->fresh()->status)->toBe(AccountStatus::NeedsReconnect);
    SocialTokens::assertChecked($page, times: 2);
});

it('asks only the connectors that check credentials, as in production', function () {
    SocialTokens::fake();

    $account = SocialAccount::factory()->forProvider('tiktok')->create();

    expect(app(SocialTokensService::class)->reportRejected($account, 'invalid_token', terminal: false))->toBeFalse();
    SocialTokens::assertNotChecked();
});

it('fails the assertions with a message', function () {
    SocialTokens::fake();

    $credential = SocialToken::factory()->create();

    expect(fn () => SocialTokens::assertRenewed($credential))
        ->toThrow(ExpectationFailedException::class, "Credential [{$credential->getKey()}] was not renewed.")
        ->and(fn () => SocialTokens::assertRevokedAtProvider())
        ->toThrow(ExpectationFailedException::class, 'Any credential was not revoked at the provider.');
});

it('registers the facade alias for package discovery', function () {
    $composer = json_decode(file_get_contents(__DIR__.'/../../../composer.json'), true);

    expect($composer['extra']['laravel']['aliases']['SocialTokens'] ?? null)->toBe(SocialTokens::class)
        ->and($composer['autoload']['psr-4']['Pr4w\\SocialTokens\\Database\\Factories\\'] ?? null)->toBe('database/factories/');
});
