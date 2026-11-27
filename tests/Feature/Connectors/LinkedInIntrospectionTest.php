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
use Illuminate\Support\Facades\Log;
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

/*
 * LinkedIn documents a 401 from introspectToken as "Invalid client secret":
 * the app's client, not the member. It was logged every day as an
 * uncatalogued error on each credential, saying nothing of the secret.
 */
it('takes a 401 as the app\'s client being refused, never as a verdict', function (int $status, array $body) {
    Http::fake(['www.linkedin.com/oauth/v2/introspectToken' => Http::response($body, $status)]);

    $result = linkedInIntrospectionCheck(linkedInIntrospectionCredential());

    expect($result->outcome)->toBe(RenewalOutcome::Transient)
        ->and($result->clientError)->toBeTrue()
        ->and($result->reason)->toContain('client_secret')->toContain('services.linkedin')
        ->and($result->context['status'])->toBe($status);
})->with([
    '401' => [401, ['error' => 'invalid_client', 'error_description' => 'Client authentication failed']],
    '401 without a body' => [401, []],
    '400 naming the client' => [400, ['error' => 'invalid_client', 'error_description' => 'The passed in client_id is invalid']],
]);

it('says what LinkedIn answered when the introspection is unexpected', function () {
    Http::fake(['www.linkedin.com/oauth/v2/introspectToken' => Http::response(['error' => 'invalid_request', 'error_description' => 'A required parameter "token" is missing'], 400)]);

    $result = linkedInIntrospectionCheck(linkedInIntrospectionCredential());

    expect($result->clientError)->toBeFalse()
        ->and($result->unknown)->toBeTrue()
        ->and($result->context)->toBe(['status' => 400, 'error' => 'invalid_request', 'error_description' => 'A required parameter "token" is missing']);
});

it('alerts once about the refused client in check-static, and flags nobody', function () {
    config(['social-tokens.check_renewable' => true]);
    Log::spy();
    Http::fake(['www.linkedin.com/oauth/v2/introspectToken' => Http::response(['error' => 'invalid_client'], 401)]);
    $first = linkedInIntrospectionCredential();
    $second = linkedInIntrospectionCredential();

    Artisan::call('social-tokens:check-static');
    Artisan::call('social-tokens:check-static');

    expect($first->fresh()->status)->toBe(AccountStatus::Active)
        ->and($second->fresh()->failed_checks)->toBe(0);

    Log::shouldHaveReceived('critical')->withArgs(fn (string $message, array $context) => str_contains($message, "linkedin rejected the app's OAuth client")
        && $context['credentials'] === 2)->twice(); // once a run, for both
    Log::shouldNotHaveReceived('error', [Mockery::on(fn ($message) => str_contains((string) $message, 'Uncatalogued')), Mockery::any()]);
    Event::assertNotDispatched(CredentialNeedsReconnect::class);
});

/*
 * Seen in production: introspectToken answered one token 401 {"error":
 * "restricted_token", "error_description": "Token restricted"}, undocumented,
 * while the same client introspected some thirty others with a 200. Not the
 * client: that token. /v2/me answered it 401 AUTHENTICATION_FAILED, and its
 * posts were failing; the check logged "Unexpected LinkedIn token
 * introspection response" every day and flagged nothing. A restricted token
 * is checked with a read it is allowed.
 */
function linkedInRestricted(array $reads = []): void
{
    Http::fake(['www.linkedin.com/oauth/v2/introspectToken' => Http::response(['error' => 'restricted_token', 'error_description' => 'Token restricted'], 401)] + $reads);
}

function linkedInRestrictedCredential(?array $scopes, ?string $refreshToken = 'refresh-1'): SocialToken
{
    $token = linkedInIntrospectionCredential($refreshToken);
    $token->forceFill(['scopes' => $scopes])->save();

    return $token->fresh();
}

it('checks a token LinkedIn will not introspect with a read it is allowed', function (?array $scopes, array $reads, string $read) {
    linkedInRestricted(array_map(fn (array $answer) => Http::response($answer[0], $answer[1] ?? 200), $reads));

    $result = linkedInIntrospectionCheck(linkedInRestrictedCredential($scopes));

    expect($result->succeeded())->toBeTrue()
        ->and($result->clientError)->toBeFalse();

    Http::assertSent(fn ($request) => str_starts_with($request->url(), $read)
        && $request->hasHeader('Authorization', 'Bearer member-token'));
})->with([
    'r_basicprofile: /v2/me' => [['r_basicprofile', 'w_member_social', 'w_organization_social'], ['api.linkedin.com/v2/me' => [['id' => 'member-1']]], 'https://api.linkedin.com/v2/me'],
    'profile: /userinfo' => [['openid', 'profile', 'w_member_social'], ['api.linkedin.com/v2/userinfo' => [['sub' => 'member-1']]], 'https://api.linkedin.com/v2/userinfo'],
    'organization admin: one grant' => [['rw_organization_admin', 'w_organization_social'], ['api.linkedin.com/rest/organizationAcls*' => [['elements' => []]]], 'https://api.linkedin.com/rest/organizationAcls?q=roleAssignee&start=0&count=1'],
    'scopes not on record: past a refused /userinfo' => [null, [
        'api.linkedin.com/v2/userinfo' => [['message' => 'Not enough permissions'], 403],
        'api.linkedin.com/v2/me' => [['id' => 'member-1']],
    ], 'https://api.linkedin.com/v2/me'],
]);

it('flags a restricted token the API refuses too, at once', function (array $answer) {
    config(['social-tokens.check_renewable' => true]);
    linkedInRestricted(['api.linkedin.com/v2/me' => Http::response($answer, 401)]);
    $credential = linkedInRestrictedCredential(['r_basicprofile', 'w_member_social']);

    $result = linkedInIntrospectionCheck($credential);

    expect($result->outcome)->toBe(RenewalOutcome::Terminal)
        ->and($result->context['definitive'])->toBeTrue()
        ->and($result->context['error'])->toBe('restricted_token');

    Artisan::call('social-tokens:check-static');

    expect($credential->fresh()->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($credential->fresh()->last_error)->toStartWith('LinkedIn token restricted and refused by the API');
    Event::assertDispatched(CredentialNeedsReconnect::class);
})->with([
    'authentication failed (production)' => [['status' => 401, 'code' => 'AUTHENTICATION_FAILED', 'message' => 'Could not authenticate']],
    'revoked' => [['serviceErrorCode' => 65601, 'message' => 'The token used in the request has been revoked by the user', 'status' => 401]],
    'revoked, /rest style' => [['status' => 401, 'code' => 'REVOKED_ACCESS_TOKEN', 'message' => 'The token used in the request has been revoked']],
    'invalid' => [['serviceErrorCode' => 65600, 'message' => 'Invalid access token', 'status' => 401]],
]);

it('flags a restricted token on the publish failure the app reports', function () {
    linkedInRestricted(['api.linkedin.com/v2/me' => Http::response(['status' => 401, 'code' => 'AUTHENTICATION_FAILED', 'message' => 'Could not authenticate'], 401)]);
    $credential = linkedInRestrictedCredential(['r_basicprofile', 'w_member_social']);

    expect(app(SocialTokens::class)->reportRejected($credential->accounts()->first(), 'HTTP 401 from the Posts API', terminal: false))->toBeTrue()
        ->and($credential->fresh()->status)->toBe(AccountStatus::NeedsReconnect);
});

it('leaves an expired restricted token to the renewal when it can refresh', function (?string $refreshToken, RenewalOutcome $outcome) {
    linkedInRestricted(['api.linkedin.com/v2/me' => Http::response(['serviceErrorCode' => 65602, 'message' => 'The token used in the request has expired', 'status' => 401], 401)]);

    expect(linkedInIntrospectionCheck(linkedInRestrictedCredential(['r_basicprofile'], $refreshToken))->outcome)->toBe($outcome);
})->with([
    'with a refresh token' => ['refresh-1', RenewalOutcome::Transient],
    'without' => [null, RenewalOutcome::Terminal],
]);

it('flags a restricted token it has no read to check with once the next run confirms it', function () {
    config(['social-tokens.check_renewable' => true]);
    linkedInRestricted();
    $credential = linkedInRestrictedCredential(['w_member_social', 'w_organization_social']);

    $result = linkedInIntrospectionCheck($credential);

    expect($result->outcome)->toBe(RenewalOutcome::Terminal)
        ->and($result->context['definitive'] ?? false)->toBeFalse()
        ->and($result->clientError)->toBeFalse()
        ->and($result->reason)->toContain('restricted_token');

    Http::assertSentCount(1);

    Artisan::call('social-tokens:check-static');
    expect($credential->fresh()->status)->toBe(AccountStatus::Active);

    Artisan::call('social-tokens:check-static');
    expect($credential->fresh()->status)->toBe(AccountStatus::NeedsReconnect);
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
