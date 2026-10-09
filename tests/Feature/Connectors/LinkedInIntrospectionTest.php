<?php

/*
 * LinkedIn token introspection answers 200 {"active": false} both for a token
 * the member revoked and for a valid token issued to another LinkedIn app than
 * the one asking (the app switched apps, or keeps a separate Community
 * Management app). Only an answer that says why (status revoked, or expired
 * when nothing can refresh it) is a verdict on the member's credential.
 */

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Events\CredentialNeedsReconnect;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

beforeEach(function () {
    Event::fake([CredentialNeedsReconnect::class]);
    config()->set('logging.default', 'null');
});

function linkedInIntrospectionAnswers(array $body): void
{
    Http::fake(['www.linkedin.com/oauth/v2/introspectToken' => Http::response($body)]);
}

function linkedInIntrospectionCredential(?string $refreshToken = null): SocialToken
{
    $token = SocialToken::create([
        'provider' => 'linkedin',
        'provider_holder_id' => 'member-'.uniqid('', true),
        'access_token' => 'member-token',
        'refresh_token' => $refreshToken,
        'expires_at' => now()->addDays(50),
        'renew_at' => now()->addDays(45),
        'status' => AccountStatus::Active,
    ]);

    SocialAccount::create([
        'provider' => 'linkedin',
        'provider_user_id' => 'org-'.uniqid('', true),
        'social_token_id' => $token->getKey(),
        'status' => AccountStatus::Active,
    ]);

    return $token;
}

function linkedInIntrospectionCheck(SocialToken $token)
{
    return app(ConnectorRegistry::class)->for('linkedin')->checkCredential($token);
}

it('does not take a bare active:false as a verdict on the member', function () {
    linkedInIntrospectionAnswers(['active' => false]);

    $result = linkedInIntrospectionCheck(linkedInIntrospectionCredential());

    expect($result->outcome)->not->toBe(RenewalOutcome::Terminal)
        ->and($result->unknown)->toBeTrue()
        ->and($result->reason)->toContain('client');
});

it('does not take an answer about another LinkedIn app\'s token as a verdict', function () {
    linkedInIntrospectionAnswers(['active' => false, 'status' => 'revoked', 'client_id' => 'another-app']);

    $result = linkedInIntrospectionCheck(linkedInIntrospectionCredential());

    expect($result->outcome)->not->toBe(RenewalOutcome::Terminal)
        ->and($result->unknown)->toBeTrue()
        ->and($result->reason)->toContain('another-app');
});

it('takes a revoked token as a definitive verdict', function () {
    linkedInIntrospectionAnswers(['active' => false, 'status' => 'revoked', 'client_id' => 'linkedin-id']);

    $result = linkedInIntrospectionCheck(linkedInIntrospectionCredential());

    expect($result->outcome)->toBe(RenewalOutcome::Terminal)
        ->and($result->context['definitive'] ?? false)->toBeTrue()
        ->and($result->reason)->toContain('revoked');
});

it('takes an expired token as a definitive verdict when nothing can refresh it', function () {
    linkedInIntrospectionAnswers(['active' => false, 'status' => 'expired']);

    $result = linkedInIntrospectionCheck(linkedInIntrospectionCredential());

    expect($result->outcome)->toBe(RenewalOutcome::Terminal)
        ->and($result->context['definitive'] ?? false)->toBeTrue();
});

it('leaves an expired access token to the refresh when refresh tokens are enabled', function () {
    config()->set('social-tokens.connectors.linkedin.refresh_enabled', true);
    app()->forgetInstance(ConnectorRegistry::class);
    linkedInIntrospectionAnswers(['active' => false, 'status' => 'expired']);

    $result = linkedInIntrospectionCheck(linkedInIntrospectionCredential('member-refresh'));

    expect($result->outcome)->toBe(RenewalOutcome::Transient);
});

it('never flags LinkedIn credentials on a bare active:false in check-static', function () {
    config(['social-tokens.check_renewable' => true]);
    linkedInIntrospectionAnswers(['active' => false]);

    $credential = linkedInIntrospectionCredential();

    Artisan::call('social-tokens:check-static');
    Artisan::call('social-tokens:check-static');

    expect($credential->fresh()->status)->toBe(AccountStatus::Active)
        ->and($credential->fresh()->failed_checks)->toBe(0);

    Event::assertNotDispatched(CredentialNeedsReconnect::class);
});

it('does not flag a reported rejection on a bare active:false', function () {
    linkedInIntrospectionAnswers(['active' => false]);

    $credential = linkedInIntrospectionCredential();

    expect(app(SocialTokens::class)->reportRejected($credential->accounts()->first(), 'HTTP 401 from the Posts API', terminal: false))->toBeFalse()
        ->and($credential->fresh()->status)->toBe(AccountStatus::Active);

    Event::assertNotDispatched(CredentialNeedsReconnect::class);
});
