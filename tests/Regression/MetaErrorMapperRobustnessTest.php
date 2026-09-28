<?php

/*
 * Regression: MetaErrorMapper must never throw, and the results it returns must
 * keep what Meta said about the failure.
 *
 * 1. A string `error` makes it throw.
 *    MetaErrorMapper::map() is typed `array $error`. The Facebook, Instagram and
 *    Threads connectors pass it `$body['error']` (or `$data['error']` for
 *    debug_token) as soon as that key is non-empty, whatever its type. A 2xx or
 *    non-429 4xx answer carrying an OAuth 2 style string error ("invalid_token")
 *    therefore raises a TypeError that AbstractConnector::attempt() does not
 *    catch, since it only wraps the HTTP call. In v1.1.0:
 *     - social-tokens:check-static aborts on that credential, and every
 *       credential after it in lazyById order goes unchecked;
 *     - RenewCredential throws a TypeError instead of the transient
 *       RuntimeException the queue retries on;
 *     - validAccessTokenFor() lets a raw TypeError escape instead of a
 *       NeedsReconnectException;
 *     - the connect-time calls (fetchPages, debug_token, the long-lived exchanges)
 *       throw instead of returning a RenewalResult.
 *    No Meta endpoint is known to answer this way today (Graph returns error
 *    objects), so this is defensive hardening.
 *    Desired: an error that is not an object is an unknown (transient) failure
 *    whose reason quotes it. No exception, at the mapper or at any call site.
 *
 * 2. Terminal and transient results lose the subcode and fbtrace_id.
 *    The reason is "{code} {type}: {message}". The error_subcode is read but never
 *    written anywhere, and the fbtrace_id is dropped. RenewalResult::terminalFailure()
 *    and transientFailure() cannot take a context. Only unknownFailure() carries
 *    {code, error_subcode, type, message, fbtrace_id}. So last_error and the
 *    CredentialNeedsReconnect event of a credential killed by 190/460 (password
 *    changed) read "190 OAuthException: ...". One killed by the session subcode
 *    463 on code 100 reads "100 ...", with nothing to show why it was terminal.
 *    Desired: the reason reads "{code}/{subcode} {type}: {message}" when Meta sends
 *    a subcode, and is unchanged when it does not. Terminal and transient results
 *    carry code, error_subcode and fbtrace_id as context, like unknown ones, and
 *    are still not flagged unknown.
 */

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Connectors\MetaErrorMapper;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Events\CredentialNeedsReconnect;
use Pr4w\SocialTokens\Exceptions\NeedsReconnectException;
use Pr4w\SocialTokens\Jobs\RenewCredential;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Pr4w\SocialTokens\Support\RenewalResult;

beforeEach(function () {
    Http::preventStrayRequests();
    Event::fake([CredentialNeedsReconnect::class]);

    // Unknown results are logged via Log::error; keep them out of the filesystem.
    config()->set('logging.default', 'null');
});

/** An OAuth 2 style error body: `error` is a string, not Meta's error object. */
function metaMapperRobustnessStringErrorBody(): array
{
    return [
        'error' => 'invalid_token',
        'error_description' => 'The access token provided is invalid.',
    ];
}

/** Meta's answer for a token killed by a password change (190/460). */
function metaMapperRobustnessPasswordChanged(): array
{
    return [
        'message' => 'Error validating access token: The session has been invalidated because the user changed their password or Facebook has changed the session for security reasons.',
        'type' => 'OAuthException',
        'code' => 190,
        'error_subcode' => 460,
        'fbtrace_id' => 'AkX9trace460',
    ];
}

/** Run $call and hand back what it returned or, if it threw, the Throwable. */
function metaMapperRobustnessOutcome(Closure $call): mixed
{
    try {
        return $call();
    } catch (Throwable $e) {
        return $e;
    }
}

/** What $outcome threw, as a readable line, or null when it is not a Throwable. */
function metaMapperRobustnessThrew(mixed $outcome): ?string
{
    return $outcome instanceof Throwable
        ? 'Threw '.get_class($outcome).': '.$outcome->getMessage()
        : null;
}

/**
 * Assert that $outcome is a RenewalResult classified unknown/transient (retried,
 * logged as uncatalogued), and not an exception.
 */
function metaMapperRobustnessExpectUnknownFailure(mixed $outcome, ?string $mentioning = null): void
{
    expect($outcome)->toBeInstanceOf(
        RenewalResult::class,
        metaMapperRobustnessThrew($outcome) ?: 'Did not return a RenewalResult.',
    );

    expect($outcome->outcome)->toBe(RenewalOutcome::Transient)
        ->and($outcome->unknown)->toBeTrue();

    if ($mentioning !== null) {
        expect($outcome->reason)->toContain($mentioning);
    }
}

/**
 * An active credential backed by one active account of the same provider. Its
 * token was last renewed two days ago, so a minimum-age guard on Meta renewals
 * (Threads, Instagram: 24h) never short-circuits the call under test.
 */
function metaMapperRobustnessCredential(string $provider, array $attrs = []): SocialToken
{
    $token = SocialToken::create(array_merge([
        'provider' => $provider,
        'provider_holder_id' => 'holder-'.uniqid('', true),
        'access_token' => 'access-token',
        'refresh_token' => null,
        'status' => AccountStatus::Active,
        'last_renewed_at' => now()->subDays(2),
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
 * Call one of the places where a Meta connector hands a response's `error` to
 * MetaErrorMapper, with the provider answering a string error.
 */
function metaMapperRobustnessCallSite(string $site): mixed
{
    Http::fake(['*' => $site === 'facebook debug_token'
        // debug_token answers 200 and nests the error under `data`.
        ? Http::response(['data' => ['error' => 'invalid_token', 'is_valid' => false]], 200)
        : Http::response(metaMapperRobustnessStringErrorBody(), 400)]);

    $tokens = app(SocialTokens::class);
    $credential = fn (string $provider) => new SocialToken([
        'provider' => $provider,
        'access_token' => 'user-token',
        'last_renewed_at' => now()->subDays(2),
    ]);

    return match ($site) {
        'facebook fb_exchange_token (renewal)' => $tokens->connector('facebook')->refreshCredential($credential('facebook')),
        'facebook /me (static check)' => $tokens->connector('facebook')->checkCredential($credential('facebook')),
        'facebook /me/accounts' => $tokens->connector('facebook')->fetchPages('user-token'),
        'facebook debug_token' => $tokens->connector('facebook')->grantedScopesByAccount('user-token', ['page-1']),
        'instagram fb_exchange_token (renewal)' => $tokens->connector('instagram')->refreshCredential($credential('facebook')),
        'threads th_refresh_token (renewal)' => $tokens->connector('threads')->refreshCredential($credential('threads')),
        'threads th_exchange_token (connect)' => $tokens->connector('threads')->exchangeForLongLived('short-lived-token'),
    };
}

// 1. A string `error` -------------------------------------------------------

it('maps an error that is not an object to an unknown transient failure instead of throwing', function (mixed $error, ?string $mentioning) {
    $result = metaMapperRobustnessOutcome(fn () => MetaErrorMapper::map($error));

    metaMapperRobustnessExpectUnknownFailure($result, $mentioning);
})->with([
    'OAuth 2 string error' => ['invalid_token', 'invalid_token'],
    'another OAuth 2 string error' => ['invalid_request', 'invalid_request'],
    'boolean' => [true, null],
]);

it('returns an unknown transient failure from every Meta call whose answer carries a string error', function (string $site) {
    $result = metaMapperRobustnessOutcome(fn () => metaMapperRobustnessCallSite($site));

    // The wording of the reason is checked on the mapper itself (above): a call
    // site may legitimately word its own failure, as long as it returns one.
    metaMapperRobustnessExpectUnknownFailure($result);
})->with([
    'facebook fb_exchange_token (renewal)',
    'facebook /me (static check)',
    'facebook /me/accounts',
    'facebook debug_token',
    'instagram fb_exchange_token (renewal)',
    'threads th_refresh_token (renewal)',
    'threads th_exchange_token (connect)',
]);

it('checks every static Facebook credential when one of them answers a string error', function () {
    $broken = metaMapperRobustnessCredential('facebook', ['access_token' => 'page-token-1']);
    $healthy = metaMapperRobustnessCredential('facebook', ['access_token' => 'page-token-2']);

    Http::fake(fn (Request $request) => str_contains($request->header('Authorization')[0] ?? '', 'page-token-1')
        ? Http::response(metaMapperRobustnessStringErrorBody(), 400)
        : Http::response(['id' => 'page-2'], 200));

    $exit = metaMapperRobustnessOutcome(fn () => Artisan::call('social-tokens:check-static'));

    expect(metaMapperRobustnessThrew($exit))->toBeNull();

    // Both credentials were asked about, the healthy one included; an unknown
    // answer flags nothing.
    foreach (['page-token-1', 'page-token-2'] as $pageToken) {
        Http::assertSent(fn (Request $request) => str_contains($request->header('Authorization')[0] ?? '', $pageToken));
    }

    expect($broken->fresh()->status)->toBe(AccountStatus::Active)
        ->and($healthy->fresh()->status)->toBe(AccountStatus::Active);

    Event::assertNotDispatched(CredentialNeedsReconnect::class);
});

it('retries a Threads renewal that answers a string error, without flagging the credential', function () {
    $token = metaMapperRobustnessCredential('threads', [
        'expires_at' => now()->addDays(3),
        'renew_at' => now()->subMinute(),
    ]);

    Http::fake(['graph.threads.net/*' => Http::response(metaMapperRobustnessStringErrorBody(), 400)]);

    $thrown = metaMapperRobustnessOutcome(function () use ($token) {
        (new RenewCredential($token))->handle(app(SocialTokens::class), app(ConnectorRegistry::class));
    });

    // The job reports a transient failure by throwing an exception, so the queue
    // retries with backoff, not a TypeError from the mapper.
    expect($thrown)->toBeInstanceOf(RuntimeException::class, (string) metaMapperRobustnessThrew($thrown))
        ->and($token->fresh()->status)->toBe(AccountStatus::Active);

    Event::assertNotDispatched(CredentialNeedsReconnect::class);
});

it('tells the publisher to retry, not to reconnect, when a synchronous Threads renewal answers a string error', function () {
    $token = metaMapperRobustnessCredential('threads', [
        'expires_at' => now()->subMinute(),
        'renew_at' => now()->subDay(),
    ]);

    Http::fake(['graph.threads.net/*' => Http::response(metaMapperRobustnessStringErrorBody(), 400)]);

    $account = $token->accounts()->firstOrFail();
    $thrown = metaMapperRobustnessOutcome(fn () => app(SocialTokens::class)->validAccessTokenFor($account));

    expect($thrown)->toBeInstanceOf(NeedsReconnectException::class, (string) metaMapperRobustnessThrew($thrown))
        ->and($thrown->transient)->toBeTrue()
        ->and($token->fresh()->status)->toBe(AccountStatus::Active);

    Event::assertNotDispatched(CredentialNeedsReconnect::class);
});

// 2. Subcode and fbtrace_id on terminal and transient results --------------

it('puts the subcode in the reason of a terminal Meta error', function (array $error, string $codes) {
    $result = MetaErrorMapper::map($error);

    expect($result->outcome)->toBe(RenewalOutcome::Terminal)
        ->and($result->reason)->toContain($codes)
        ->and($result->reason)->toContain($error['message']);
})->with([
    'password changed' => [metaMapperRobustnessPasswordChanged(), '190/460'],
    'user logged out' => [['type' => 'OAuthException', 'code' => 190, 'error_subcode' => 467, 'message' => 'The user has logged out.'], '190/467'],
    'session subcode on another code' => [['type' => 'OAuthException', 'code' => 100, 'error_subcode' => 463, 'message' => 'Session has expired.'], '100/463'],
]);

it('keeps the plain code in the reason when Meta sends no subcode', function () {
    $result = MetaErrorMapper::map(['type' => 'OAuthException', 'code' => 190, 'message' => 'Token expired']);

    expect($result->outcome)->toBe(RenewalOutcome::Terminal)
        ->and($result->reason)->toStartWith('190 OAuthException: Token expired');
});

it('gives terminal Meta results the code, subcode and fbtrace_id as context', function (array $error, int $code, ?int $subcode) {
    $result = MetaErrorMapper::map($error + ['message' => 'nope', 'fbtrace_id' => 'AkX9trace']);

    expect($result->outcome)->toBe(RenewalOutcome::Terminal)
        ->and($result->unknown)->toBeFalse()
        ->and($result->context)->toMatchArray(['code' => $code, 'fbtrace_id' => 'AkX9trace'])
        // Without a subcode the key may be null or left out.
        ->and($result->context['error_subcode'] ?? null)->toEqual($subcode);
})->with([
    'password changed' => [['type' => 'OAuthException', 'code' => 190, 'error_subcode' => 460], 190, 460],
    'invalid token, no subcode' => [['type' => 'OAuthException', 'code' => 190], 190, null],
    'session subcode on another code' => [['code' => 100, 'error_subcode' => 463], 100, 463],
    'missing permission' => [['type' => 'OAuthException', 'code' => 200], 200, null],
]);

it('gives transient Meta results the code, subcode and fbtrace_id as context', function (array $error, int $code, ?int $subcode) {
    $result = MetaErrorMapper::map($error + ['message' => 'slow down', 'fbtrace_id' => 'AkX9trace']);

    expect($result->outcome)->toBe(RenewalOutcome::Transient)
        ->and($result->unknown)->toBeFalse()
        ->and($result->context)->toMatchArray(['code' => $code, 'fbtrace_id' => 'AkX9trace'])
        // Without a subcode the key may be null or left out.
        ->and($result->context['error_subcode'] ?? null)->toEqual($subcode);
})->with([
    'app rate limit' => [['type' => 'OAuthException', 'code' => 4], 4, null],
    'business use case limit with subcode' => [['type' => 'OAuthException', 'code' => 80001, 'error_subcode' => 2446079], 80001, 2446079],
    'flagged transient by Meta' => [['code' => 999, 'error_subcode' => 12, 'is_transient' => true], 999, 12],
]);

it('lets terminal and transient failures carry a context without being flagged unknown', function () {
    $terminal = RenewalResult::terminalFailure('190/460 OAuthException: gone', ['code' => 190, 'error_subcode' => 460]);
    $transient = RenewalResult::transientFailure('4 OAuthException: slow down', ['code' => 4]);

    expect($terminal->context)->toBe(['code' => 190, 'error_subcode' => 460])
        ->and($terminal->outcome)->toBe(RenewalOutcome::Terminal)
        ->and($terminal->unknown)->toBeFalse()
        ->and($transient->context)->toBe(['code' => 4])
        ->and($transient->outcome)->toBe(RenewalOutcome::Transient)
        ->and($transient->unknown)->toBeFalse()
        // The context stays optional.
        ->and(RenewalResult::terminalFailure('gone')->context)->toBe([])
        ->and(RenewalResult::transientFailure('later')->context)->toBe([]);
});

it('stores the subcode in last_error and in the reconnect event when a Threads renewal is refused', function () {
    $token = metaMapperRobustnessCredential('threads', [
        'expires_at' => now()->addDays(3),
        'renew_at' => now()->subMinute(),
    ]);

    Http::fake(['graph.threads.net/*' => Http::response(['error' => metaMapperRobustnessPasswordChanged()], 400)]);

    (new RenewCredential($token))->handle(app(SocialTokens::class), app(ConnectorRegistry::class));

    $fresh = $token->fresh();

    expect($fresh->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($fresh->last_error)->toContain('190/460')
        ->and($fresh->last_error)->toContain('changed their password');

    Event::assertDispatched(
        CredentialNeedsReconnect::class,
        fn (CredentialNeedsReconnect $event) => $event->token->is($token) && str_contains((string) $event->reason, '190/460'),
    );
});
