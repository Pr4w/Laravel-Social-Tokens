<?php

/*
 * Regression: an OAuth error about the APP's client (bad or missing client id or
 * secret, a deleted OAuth client, a client not allowed to use the refresh grant)
 * must not flag the MEMBER's credential.
 *
 * In v1.1.0 LinkedIn (invalid_client, unauthorized_client, invalid_request),
 * TikTok (invalid_client, invalid_request) and Google (invalid_client,
 * unauthorized_client) classify these as Terminal. RenewCredential then marks
 * every due credential of that provider needs_reconnect and fires
 * CredentialNeedsReconnect (which apps turn into "reconnect" emails): one bad
 * or missing env var cuts off still-valid tokens early and leaves a sticky flag
 * that forces every user to re-authorise even though no refresh token was
 * consumed. Reconnecting cannot help anyway: the OAuth flow uses the same
 * broken client.
 *
 * Desired: these errors are not terminal (retried, the credential stays usable),
 * they raise a critical log naming the provider so the operator notices, and
 * they are never escalated to needs_reconnect, not even by RenewCredential::failed()
 * once the access token has expired. Errors about the member's grant stay
 * terminal: invalid_grant, LinkedIn's refresh_token_client_mismatch, and an
 * invalid_request whose description is about the refresh token.
 */

use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Events\CredentialNeedsReconnect;
use Pr4w\SocialTokens\Exceptions\NeedsReconnectException;
use Pr4w\SocialTokens\Jobs\RenewCredential;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

beforeEach(function () {
    Event::fake([CredentialNeedsReconnect::class]);

    // Keep the alerts a fix raises out of the testbench log file; MessageLogged
    // is still dispatched, which is what the logging test listens to.
    config()->set('logging.default', 'null');

    // LinkedIn only calls its token endpoint when refresh tokens are enabled.
    config()->set('social-tokens.connectors.linkedin.refresh_enabled', true);
    app()->forgetInstance(ConnectorRegistry::class);
    app()->forgetInstance(SocialTokens::class);
});

/** The refresh endpoint each connector posts to (Http::fake pattern). */
function oauthClientErrorTokenUrl(string $provider): string
{
    return match ($provider) {
        'linkedin' => 'www.linkedin.com/oauth/v2/accessToken',
        'tiktok' => 'open.tiktokapis.com/v2/oauth/token/',
        'google' => 'oauth2.googleapis.com/token',
    };
}

/** Make the provider's refresh endpoint answer with an OAuth error body. */
function oauthClientErrorProviderAnswers(string $provider, int $status, string $error, string $description): void
{
    Http::fake([oauthClientErrorTokenUrl($provider) => Http::response([
        'error' => $error,
        'error_description' => $description,
    ], $status)]);
}

/**
 * An active credential that is due for renewal, backed by one active account.
 * By default its access token is still valid for an hour.
 */
function oauthClientErrorCredential(string $provider, array $attrs = []): SocialToken
{
    $token = SocialToken::create(array_merge([
        'provider' => $provider,
        'provider_holder_id' => 'holder-'.uniqid('', true),
        'access_token' => 'access-1',
        'refresh_token' => 'refresh-1',
        'status' => AccountStatus::Active,
        'expires_at' => now()->addHour(),
        'renew_at' => now()->subMinute(),
    ], $attrs));

    SocialAccount::create([
        'provider' => $provider,
        'provider_user_id' => 'account-'.uniqid('', true),
        'social_token_id' => $token->getKey(),
        'status' => AccountStatus::Active,
    ]);

    return $token;
}

/**
 * Run the renewal job the way a queue worker would. A thrown exception is what
 * makes the queue retry; on the final attempt the worker then calls failed().
 */
function oauthClientErrorRunJob(SocialToken $token, bool $finalAttempt = false): void
{
    $job = new RenewCredential($token);

    try {
        $job->handle(app(SocialTokens::class), app(ConnectorRegistry::class));
    } catch (Exception $e) {
        if ($finalAttempt) {
            $job->failed($e);
        }
    }
}

// Error bodies returned when the app's own OAuth client is misconfigured.
dataset('app client errors', [
    'LinkedIn invalid_client (wrong secret)' => ['linkedin', 401, 'invalid_client', 'Client authentication failed'],
    'LinkedIn unauthorized_client' => ['linkedin', 400, 'unauthorized_client', 'The client is not authorized to request an access token using this method'],
    'LinkedIn invalid_request (client_id missing from config)' => ['linkedin', 400, 'invalid_request', 'A required parameter "client_id" is missing'],
    'TikTok invalid_client (wrong or rotated secret)' => ['tiktok', 401, 'invalid_client', 'Client key or secret is incorrect.'],
    'TikTok invalid_request (client key missing from config)' => ['tiktok', 400, 'invalid_request', 'The request parameters are malformed.'],
    'Google invalid_client (OAuth client deleted)' => ['google', 401, 'invalid_client', 'The OAuth client was not found.'],
    'Google invalid_client (wrong secret)' => ['google', 401, 'invalid_client', 'Unauthorized'],
    'Google unauthorized_client' => ['google', 400, 'unauthorized_client', 'Unauthorized'],
]);

// One representative app misconfiguration per provider, for the flow tests.
dataset('misconfigured app clients', [
    'LinkedIn, client_id missing from config' => ['linkedin', 400, 'invalid_request', 'A required parameter "client_id" is missing'],
    'TikTok, rotated client secret' => ['tiktok', 401, 'invalid_client', 'Client key or secret is incorrect.'],
    'Google, OAuth client deleted' => ['google', 401, 'invalid_client', 'The OAuth client was not found.'],
]);

it('does not classify an app client error as terminal', function (string $provider, int $status, string $error, string $description) {
    oauthClientErrorProviderAnswers($provider, $status, $error, $description);

    $result = app(ConnectorRegistry::class)->for($provider)
        ->refreshCredential(oauthClientErrorCredential($provider));

    // The stubbed body was read (guards against a URL pattern that never matched).
    expect($result->reason)->toContain($error)
        ->and($result->outcome)->not->toBe(RenewalOutcome::Terminal);
})->with('app client errors');

it('keeps every due credential of the provider active when the app client is misconfigured', function (string $provider, int $status, string $error, string $description) {
    oauthClientErrorProviderAnswers($provider, $status, $error, $description);

    $tokens = collect(range(1, 3))->map(fn () => oauthClientErrorCredential($provider));

    $tokens->each(fn (SocialToken $token) => oauthClientErrorRunJob($token));

    // At least one refresh reached the provider (a fix may stop calling it for
    // the other credentials once the client is known to be rejected).
    Http::assertSent(fn (Request $request) => str_contains($request->url(), oauthClientErrorTokenUrl($provider)));

    $tokens->each(function (SocialToken $token) {
        $fresh = $token->fresh();

        expect($fresh->status)->toBe(AccountStatus::Active)
            ->and($fresh->accounts()->first()->effectiveStatus())->toBe(AccountStatus::Active);
    });

    Event::assertNotDispatched(CredentialNeedsReconnect::class);
})->with('misconfigured app clients');

it('does not flag an expired credential after the last retry when the app client is misconfigured', function (string $provider, int $status, string $error, string $description) {
    oauthClientErrorProviderAnswers($provider, $status, $error, $description);

    // The config stayed broken long enough for the access token to expire
    // (an hour for Google): the refresh token itself is still good.
    $token = oauthClientErrorCredential($provider, [
        'expires_at' => now()->subMinutes(5),
        'renew_at' => now()->subHour(),
    ]);

    oauthClientErrorRunJob($token, finalAttempt: true);

    expect($token->fresh()->status)->toBe(AccountStatus::Active);
    Event::assertNotDispatched(CredentialNeedsReconnect::class);
})->with('misconfigured app clients');

it('tells the publisher to retry, not to reconnect, when the app client is misconfigured', function (string $provider, int $status, string $error, string $description) {
    oauthClientErrorProviderAnswers($provider, $status, $error, $description);

    $token = oauthClientErrorCredential($provider, ['expires_at' => now()->subMinutes(5)]);
    $account = $token->accounts()->first();

    $thrown = null;

    try {
        app(SocialTokens::class)->validAccessTokenFor($account);
    } catch (NeedsReconnectException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(NeedsReconnectException::class)
        ->and($thrown->transient)->toBeTrue('A misconfigured app client must not ask the user to reconnect.')
        ->and($token->fresh()->status)->toBe(AccountStatus::Active);

    Event::assertNotDispatched(CredentialNeedsReconnect::class);
})->with('misconfigured app clients');

it('logs a critical alert naming the provider when the app client is rejected', function (string $provider, int $status, string $error, string $description) {
    // The alert must not depend on the "uncatalogued errors" switch: this error
    // is known, and only the operator can fix it. Critical or above, because every
    // credential of the provider is stuck until someone fixes the config (PSR-3
    // "component unavailable"), and it is the level Laravel's stock slack channel
    // forwards.
    config()->set('social-tokens.log_unknown_errors', false);

    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$logged) {
        $logged[] = $log;
    });

    oauthClientErrorProviderAnswers($provider, $status, $error, $description);

    oauthClientErrorRunJob(oauthClientErrorCredential($provider));

    $alerts = collect($logged)
        ->filter(fn (MessageLogged $log) => in_array($log->level, ['critical', 'alert', 'emergency'], true))
        ->map(fn (MessageLogged $log) => $log->message.' '.json_encode($log->context));

    expect($alerts)->not->toBeEmpty("No critical log for a rejected {$provider} app client.")
        ->and($alerts->implode("\n"))
        ->toContain($provider)
        ->toContain($error)
        ->not->toContain("{$provider}-secret");
})->with('misconfigured app clients');

it('renews normally without a reconnect once a missing LinkedIn client_id is restored', function () {
    // A real-looking LinkedIn: without client_id in the form body it answers
    // invalid_request, with it the refresh succeeds.
    Http::fake([oauthClientErrorTokenUrl('linkedin') => function (Request $request) {
        if (blank($request->data()['client_id'] ?? null)) {
            return Http::response([
                'error' => 'invalid_request',
                'error_description' => 'A required parameter "client_id" is missing',
            ], 400);
        }

        return Http::response(['access_token' => 'access-2', 'expires_in' => 5184000]);
    }]);

    $token = oauthClientErrorCredential('linkedin', ['expires_at' => now()->addDays(4)]);

    config()->set('services.linkedin.client_id', null); // LINKEDIN_CLIENT_ID dropped by a deploy
    oauthClientErrorRunJob($token);

    // The operator sees the alert and restores the variable an hour later.
    config()->set('services.linkedin.client_id', 'linkedin-id');
    $this->travel(1)->hours();

    // The next dispatcher pass renews it (a fix may have backed renew_at off a
    // little after the failed attempt: run once it is due again).
    if ($token->fresh()->renew_at?->isFuture()) {
        $this->travelTo($token->fresh()->renew_at->copy()->addMinute());
    }

    oauthClientErrorRunJob($token);

    $fresh = $token->fresh();

    expect($fresh->status)->toBe(AccountStatus::Active)
        ->and($fresh->access_token)->toBe('access-2');

    Event::assertNotDispatched(CredentialNeedsReconnect::class);
});

it('still flags the credential when the provider rejects the member grant', function (string $provider, string $error, string $description) {
    oauthClientErrorProviderAnswers($provider, 400, $error, $description);

    $token = oauthClientErrorCredential($provider);

    oauthClientErrorRunJob($token);

    $fresh = $token->fresh();

    expect($fresh->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($fresh->last_error)->toContain($error);

    Event::assertDispatchedTimes(CredentialNeedsReconnect::class, 1);
})->with([
    'LinkedIn invalid_grant' => ['linkedin', 'invalid_grant', 'The provided authorization grant or refresh token is invalid, expired or revoked'],
    'LinkedIn refresh_token_client_mismatch' => ['linkedin', 'refresh_token_client_mismatch', 'The passed in client_id does not own the refresh token'],
    'LinkedIn invalid_request about the refresh token' => ['linkedin', 'invalid_request', 'The provided refresh token is invalid, expired or revoked'],
    'TikTok invalid_grant' => ['tiktok', 'invalid_grant', 'Refresh token is invalid or expired.'],
    'TikTok invalid_request about the refresh token' => ['tiktok', 'invalid_request', 'Invalid refresh_token.'],
    'Google invalid_grant' => ['google', 'invalid_grant', 'Token has been expired or revoked.'],
]);

it('keeps treating a Google request without a client id as retryable', function () {
    // What Google answers when GOOGLE_CLIENT_ID is missing: already not
    // terminal in v1.1.0, and must stay so.
    oauthClientErrorProviderAnswers('google', 400, 'invalid_request', 'Could not determine client ID from request.');

    $result = app(ConnectorRegistry::class)->for('google')
        ->refreshCredential(oauthClientErrorCredential('google'));

    expect($result->reason)->toContain('invalid_request')
        ->and($result->outcome)->toBe(RenewalOutcome::Transient);
});
