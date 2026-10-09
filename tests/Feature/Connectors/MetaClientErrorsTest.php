<?php

/*
 * Meta rejects the APP's own credentials (a wrong or rotated app secret, a
 * wrong app id, an appsecret_proof made with the wrong secret) with Graph
 * errors that say so. Like invalid_client on the OAuth 2 providers, these are
 * the operator's problem, not the member's: a client failure, alerted as
 * critical, never escalated to needs_reconnect. Code 1 on its own stays
 * Meta's generic "unknown error, retry".
 */

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Connectors\MetaErrorMapper;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Events\CredentialNeedsReconnect;
use Pr4w\SocialTokens\Jobs\RenewCredential;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

beforeEach(function () {
    Event::fake([CredentialNeedsReconnect::class]);
    config()->set('logging.default', 'null');
    config()->set('social-tokens.log_unknown_errors', false);
});

dataset('meta app credential errors', [
    'wrong app secret' => [['message' => 'Error validating client secret.', 'type' => 'OAuthException', 'code' => 1]],
    'wrong app id' => [['message' => 'Error validating application. Invalid application ID.', 'type' => 'OAuthException', 'code' => 101]],
    'app id missing from config' => [['message' => 'Missing client_id parameter.', 'type' => 'OAuthException', 'code' => 101]],
    'proof made with the wrong secret' => [['message' => 'Invalid appsecret_proof provided in the API argument', 'type' => 'GraphMethodException', 'code' => 100]],
    'proof required but not sent' => [['message' => 'API calls from the server require an appsecret_proof argument', 'type' => 'GraphMethodException', 'code' => 100]],
]);

/** @return ArrayObject<int, MessageLogged> */
function metaClientErrorLogs(): ArrayObject
{
    $logs = new ArrayObject;
    Event::listen(MessageLogged::class, fn (MessageLogged $log) => $logs->append($log));

    return $logs;
}

/** @param  ArrayObject<int, MessageLogged>  $logs */
function metaClientErrorCriticals(ArrayObject $logs): array
{
    return array_values(array_filter(
        $logs->getArrayCopy(),
        fn (MessageLogged $log) => in_array($log->level, ['critical', 'alert', 'emergency'], true),
    ));
}

/** The Meta user credential behind Instagram accounts, due for renewal. */
function metaClientErrorUserCredential(array $attrs = []): SocialToken
{
    $token = SocialToken::create(array_merge([
        'provider' => 'facebook',
        'provider_holder_id' => 'fb-user-'.uniqid('', true),
        'access_token' => 'meta-user-token',
        'expires_at' => now()->addDays(5),
        'renew_at' => now()->subMinute(),
        'status' => AccountStatus::Active,
    ], $attrs));

    SocialAccount::create([
        'provider' => 'instagram',
        'provider_user_id' => 'ig-'.uniqid('', true),
        'social_token_id' => $token->getKey(),
        'status' => AccountStatus::Active,
    ]);

    return $token;
}

function metaClientErrorRunJob(SocialToken $token, bool $finalAttempt = false): void
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

it('classifies an app credential error as a client failure', function (array $error) {
    foreach ([MetaErrorMapper::map($error), MetaErrorMapper::mapCredentialCheck($error)] as $result) {
        expect($result->clientError)->toBeTrue()
            ->and($result->outcome)->toBe(RenewalOutcome::Transient)
            ->and($result->unknown)->toBeFalse()
            ->and($result->reason)->toContain($error['message']);
    }
})->with('meta app credential errors');

it('keeps a plain code 1 an ordinary transient error', function () {
    $result = MetaErrorMapper::map(['message' => 'An unknown error has occurred.', 'type' => 'OAuthException', 'code' => 1]);

    expect($result->outcome)->toBe(RenewalOutcome::Transient)
        ->and($result->clientError)->toBeFalse();
});

it('keeps an invalid user token terminal even when the message mentions the application', function () {
    $result = MetaErrorMapper::map([
        'message' => 'Error validating access token: The user has not authorized application 123.',
        'type' => 'OAuthException',
        'code' => 190,
        'error_subcode' => 458,
    ]);

    expect($result->outcome)->toBe(RenewalOutcome::Terminal)
        ->and($result->clientError)->toBeFalse();
});

it('takes an OAuth-style invalid_client string as a client failure', function () {
    $result = MetaErrorMapper::map('invalid_client');

    expect($result->clientError)->toBeTrue()
        ->and($result->outcome)->toBe(RenewalOutcome::Transient);
});

it('alerts and keeps the Meta user credential when the app secret is rejected at renewal', function () {
    $logs = metaClientErrorLogs();
    Http::fake(['graph.facebook.com/*/oauth/access_token*' => Http::response(['error' => [
        'message' => 'Error validating client secret.', 'type' => 'OAuthException', 'code' => 1, 'fbtrace_id' => 'trace',
    ]], 400)]);

    $token = metaClientErrorUserCredential();

    metaClientErrorRunJob($token);

    expect($token->fresh()->status)->toBe(AccountStatus::Active);
    Event::assertNotDispatched(CredentialNeedsReconnect::class);

    $alerts = metaClientErrorCriticals($logs);

    expect($alerts)->not->toBeEmpty('A rejected Meta app secret raised no critical log.')
        ->and($alerts[0]->message)->toContain('facebook')
        ->and(json_encode($alerts[0]->context))->toContain('Error validating client secret')
        ->and(json_encode($alerts[0]->context))->not->toContain('facebook-secret');
});

it('does not flag an expired Meta user credential after the last retry when the app secret is rejected', function () {
    Http::fake(['graph.facebook.com/*/oauth/access_token*' => Http::response(['error' => [
        'message' => 'Error validating client secret.', 'type' => 'OAuthException', 'code' => 1,
    ]], 400)]);

    $token = metaClientErrorUserCredential(['expires_at' => now()->subMinutes(5), 'renew_at' => now()->subDays(7)]);

    metaClientErrorRunJob($token, finalAttempt: true);

    expect($token->fresh()->status)->toBe(AccountStatus::Active);
    Event::assertNotDispatched(CredentialNeedsReconnect::class);
});

it('alerts once per run and flags nothing when check-static is refused for the app secret', function () {
    $logs = metaClientErrorLogs();
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => [
        'message' => 'Invalid appsecret_proof provided in the API argument', 'type' => 'GraphMethodException', 'code' => 100,
    ]], 400)]);

    $pages = collect(range(1, 3))->map(function (int $i) {
        $token = SocialToken::create([
            'provider' => 'facebook',
            'provider_holder_id' => "page-{$i}",
            'access_token' => "page-token-{$i}",
            'status' => AccountStatus::Active,
        ]);

        SocialAccount::create(['provider' => 'facebook', 'provider_user_id' => "page-{$i}", 'social_token_id' => $token->getKey(), 'status' => AccountStatus::Active]);

        return $token;
    });

    Artisan::call('social-tokens:check-static');
    Artisan::call('social-tokens:check-static');

    expect($pages->every(fn (SocialToken $token) => $token->fresh()->status === AccountStatus::Active))->toBeTrue();
    Event::assertNotDispatched(CredentialNeedsReconnect::class);

    $alerts = metaClientErrorCriticals($logs);

    expect($alerts)->toHaveCount(2, 'Expected one critical log per run.')
        ->and($alerts[0]->context['provider'] ?? null)->toBe('facebook')
        ->and($alerts[0]->context['credentials'] ?? null)->toBe(3);
});

it('alerts and flags nothing when a reported rejection meets an app credential error', function () {
    $logs = metaClientErrorLogs();
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => [
        'message' => 'Invalid appsecret_proof provided in the API argument', 'type' => 'GraphMethodException', 'code' => 100,
    ]], 400)]);

    $token = metaClientErrorUserCredential(['renew_at' => now()->addDays(40), 'expires_at' => now()->addDays(47)]);

    expect(app(SocialTokens::class)->reportRejected($token->accounts()->first(), 'HTTP 400 from the media endpoint', terminal: false))->toBeFalse()
        ->and($token->fresh()->status)->toBe(AccountStatus::Active)
        ->and(metaClientErrorCriticals($logs))->not->toBeEmpty();
});
