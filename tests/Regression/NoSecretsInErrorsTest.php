<?php

/*
 * Regression: secrets must never leave the package in an error message or a
 * serialised model.
 *
 * In v1.1.0 AbstractConnector::attempt() builds the RenewalResult reason of a
 * transport failure from the exception message ("Connection error: ..." /
 * "Unexpected error: ..."). Guzzle ends that message with the full request URI,
 * query string included. Every Meta call that authenticates through the query
 * therefore leaks: the app secret (client_secret=, and the "{id}|{secret}" app
 * token of debug_token), the user token (fb_exchange_token=, input_token=) and
 * the Threads token (access_token=). The reason then travels to:
 *  - the RuntimeException RenewCredential throws (laravel.log, failed_jobs,
 *    error trackers);
 *  - the RuntimeExceptions StoreFacebookPages, StoreInstagramAccounts and
 *    StoreAccountFromSocialite throw at connect time;
 *  - NeedsReconnectException::transient() on a synchronous renewal;
 *  - social_tokens.last_error (a plain, unencrypted column) and the
 *    CredentialNeedsReconnect event, once RenewCredential::failed() gives up.
 *
 * Separately, SocialToken declares no $hidden, so toArray()/toJson() of a
 * credential, or of SocialAccount::with('credential') as the README suggests,
 * returns access_token and refresh_token decrypted.
 *
 * Desired: no secret value ever reaches a reason, an exception message,
 * last_error, an event or a log line, and SocialToken hides access_token and
 * refresh_token from serialisation while keeping them readable as attributes.
 * How the URL is sanitised (whole query dropped, or only the sensitive values
 * masked) is left to the fix: the tests look for the secret values only.
 *
 * The connection failures below are faked with the exact message format
 * Guzzle's cURL handler produces ("cURL error N: ... for <full URI>"), so no
 * real network call is made.
 */

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Actions\StoreAccountFromSocialite;
use Pr4w\SocialTokens\Actions\StoreFacebookPages;
use Pr4w\SocialTokens\Actions\StoreInstagramAccounts;
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

/** Distinctive secret values, so a leak is unambiguous in a failure message. */
function noSecretsInErrorsMetaSecret(): string
{
    return 'meta-app-secret-7f3a91';
}

function noSecretsInErrorsThreadsSecret(): string
{
    return 'threads-app-secret-c42e08';
}

function noSecretsInErrorsUserToken(): string
{
    return 'EAAGuserTOKENd4c9b2';
}

function noSecretsInErrorsThreadsToken(): string
{
    return 'THQWthreadsTOKEN5e17aa';
}

/**
 * Every value that must never show up in a reason, an exception message,
 * last_error, an event or a log entry. Only the values are checked, not the
 * query keys that carry them (client_secret=, access_token=, ...): a fix that
 * masks the values but keeps the keys (client_secret=[redacted]) is just as
 * safe. All values are URL-safe, so Guzzle never percent-encodes them.
 *
 * @return array<int, string>
 */
function noSecretsInErrorsNeedles(): array
{
    return [
        noSecretsInErrorsMetaSecret(),
        noSecretsInErrorsThreadsSecret(),
        noSecretsInErrorsUserToken(),
        noSecretsInErrorsThreadsToken(),
    ];
}

function noSecretsInErrorsAssertClean(mixed $text): void
{
    expect($text)->toBeString();

    foreach (noSecretsInErrorsNeedles() as $needle) {
        expect(str_contains($text, $needle))->toBeFalse("Leaked [{$needle}] in: {$text}");
    }
}

/**
 * Every provider call fails at the transport level, with the message Guzzle's
 * cURL handler really produces: it ends with the full request URI, query
 * string included (only the userinfo part is redacted by Guzzle).
 */
function noSecretsInErrorsFailConnections(): void
{
    Http::fake(fn (Request $request) => Create::rejectionFor(new ConnectException(
        'cURL error 28: Connection timed out after 5001 milliseconds (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for '
            .$request->toPsrRequest()->getUri(),
        $request->toPsrRequest(),
    )));
}

function noSecretsInErrorsCredential(string $provider, string $accessToken, array $attrs = []): SocialToken
{
    return SocialToken::create(array_merge([
        'provider' => $provider,
        'provider_holder_id' => "{$provider}-holder-1",
        'access_token' => $accessToken,
        'status' => AccountStatus::Active,
    ], $attrs));
}

beforeEach(function () {
    config()->set('services.facebook.client_secret', noSecretsInErrorsMetaSecret());
    config()->set('services.threads.client_secret', noSecretsInErrorsThreadsSecret());

    app()->forgetInstance(ConnectorRegistry::class);
    app()->forgetInstance(SocialTokens::class);
});

// Connector reasons -----------------------------------------------------------

it('keeps client_secret and the user token out of the reason when the Facebook token extension cannot connect', function () {
    noSecretsInErrorsFailConnections();

    $result = app(ConnectorRegistry::class)->for('facebook')->extendUserToken(noSecretsInErrorsUserToken());

    // Still a transient, retryable failure: only its wording changes.
    expect($result)->toBeInstanceOf(RenewalResult::class)
        ->and($result->outcome)->toBe(RenewalOutcome::Transient);

    noSecretsInErrorsAssertClean($result->reason);
});

it('keeps the app token and the user token out of the reason when debug_token cannot connect', function () {
    noSecretsInErrorsFailConnections();

    $result = app(ConnectorRegistry::class)->for('facebook')
        ->grantedScopesByAccount(noSecretsInErrorsUserToken(), ['1001']);

    expect($result)->toBeInstanceOf(RenewalResult::class)
        ->and($result->outcome)->toBe(RenewalOutcome::Transient);

    noSecretsInErrorsAssertClean($result->reason);
});

it('keeps client_secret and the user token out of the reason when the Instagram extension cannot connect', function () {
    noSecretsInErrorsFailConnections();

    $result = app(ConnectorRegistry::class)->for('instagram')->exchangeForLongLived(noSecretsInErrorsUserToken());

    expect($result)->toBeInstanceOf(RenewalResult::class)
        ->and($result->outcome)->toBe(RenewalOutcome::Transient);

    noSecretsInErrorsAssertClean($result->reason);
});

it('keeps the Threads access token out of the reason when the Threads refresh cannot connect', function () {
    noSecretsInErrorsFailConnections();

    $token = noSecretsInErrorsCredential('threads', noSecretsInErrorsThreadsToken());

    $result = app(ConnectorRegistry::class)->for('threads')->refreshCredential($token);

    expect($result->outcome)->toBe(RenewalOutcome::Transient);

    noSecretsInErrorsAssertClean($result->reason);
});

it('keeps client_secret and the short-lived token out of the reason when the Threads exchange cannot connect', function () {
    noSecretsInErrorsFailConnections();

    $result = app(ConnectorRegistry::class)->for('threads')->exchangeForLongLived(noSecretsInErrorsThreadsToken());

    expect($result)->toBeInstanceOf(RenewalResult::class)
        ->and($result->outcome)->toBe(RenewalOutcome::Transient);

    noSecretsInErrorsAssertClean($result->reason);
});

it('keeps the user token out of the reason when a later /me/accounts page cannot connect', function () {
    // Meta's paging.next is a complete URL that carries the user token in its
    // query; fetchPages() follows it as-is.
    Http::fake(function (Request $request) {
        if (! str_contains($request->url(), 'after=')) {
            return Http::response([
                'data' => [['id' => '1001', 'name' => 'Page 1', 'access_token' => 'page-token-1']],
                'paging' => ['next' => 'https://graph.facebook.com/v23.0/me/accounts?access_token='
                    .noSecretsInErrorsUserToken().'&fields=id%2Cname&limit=100&after=CURSOR1'],
            ]);
        }

        return Create::rejectionFor(new ConnectException(
            'cURL error 28: Connection timed out after 5001 milliseconds (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for '
                .$request->toPsrRequest()->getUri(),
            $request->toPsrRequest(),
        ));
    });

    $result = app(ConnectorRegistry::class)->for('facebook')->fetchPages(noSecretsInErrorsUserToken());

    expect($result)->toBeInstanceOf(RenewalResult::class)
        ->and($result->outcome)->toBe(RenewalOutcome::Transient);

    noSecretsInErrorsAssertClean($result->reason);
});

it('keeps secrets out of the reason when the transport throws an unexpected exception quoting the URI', function () {
    // Any other transport exception (not a ConnectException) lands in the
    // "Unexpected error" branch of attempt(); its message is just as unsafe.
    Http::fake(fn (Request $request) => Create::rejectionFor(new TransferException(
        'Error completing request for '.$request->toPsrRequest()->getUri(),
    )));

    $result = app(ConnectorRegistry::class)->for('facebook')->extendUserToken(noSecretsInErrorsUserToken());

    expect($result)->toBeInstanceOf(RenewalResult::class)
        ->and($result->outcome)->toBe(RenewalOutcome::Transient);

    noSecretsInErrorsAssertClean($result->reason);
});

// Where the reason travels ----------------------------------------------------

it('keeps secrets out of the exception RenewCredential throws for the queue to log', function () {
    noSecretsInErrorsFailConnections();

    $token = noSecretsInErrorsCredential('facebook', noSecretsInErrorsUserToken(), [
        'expires_at' => now()->addDays(3),
        'renew_at' => now()->subHour(),
    ]);

    $caught = null;

    try {
        (new RenewCredential($token))->handle(app(SocialTokens::class), app(ConnectorRegistry::class));
    } catch (RuntimeException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(RuntimeException::class);

    noSecretsInErrorsAssertClean($caught->getMessage());
});

it('keeps secrets out of last_error and CredentialNeedsReconnect when retries run out on an expired credential', function () {
    Event::fake([CredentialNeedsReconnect::class]);
    noSecretsInErrorsFailConnections();

    $token = noSecretsInErrorsCredential('facebook', noSecretsInErrorsUserToken(), [
        'expires_at' => now()->subMinute(),
        'renew_at' => now()->subDay(),
    ]);

    $job = new RenewCredential($token);
    $caught = null;

    try {
        $job->handle(app(SocialTokens::class), app(ConnectorRegistry::class));
    } catch (RuntimeException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(RuntimeException::class);

    // What the queue does after the last attempt.
    $job->failed($caught);

    $fresh = $token->fresh();

    expect($fresh->status)->toBe(AccountStatus::NeedsReconnect);
    noSecretsInErrorsAssertClean($fresh->last_error);

    Event::assertDispatched(CredentialNeedsReconnect::class, function (CredentialNeedsReconnect $event) {
        noSecretsInErrorsAssertClean($event->reason);

        return true;
    });
});

it('keeps secrets out of NeedsReconnectException when a synchronous renewal cannot connect', function () {
    noSecretsInErrorsFailConnections();

    $token = noSecretsInErrorsCredential('threads', noSecretsInErrorsThreadsToken(), [
        'expires_at' => now()->subMinute(),
        'renew_at' => now()->subDay(),
    ]);

    $account = SocialAccount::create([
        'provider' => 'threads',
        'provider_user_id' => 'threads-user-1',
        'social_token_id' => $token->getKey(),
        'status' => AccountStatus::Active,
    ]);

    $caught = null;

    try {
        app(SocialTokens::class)->validAccessTokenFor($account);
    } catch (NeedsReconnectException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(NeedsReconnectException::class)
        ->and($caught->transient)->toBeTrue();

    noSecretsInErrorsAssertClean($caught->getMessage());
});

it('keeps secrets out of the exception StoreFacebookPages throws when the token extension cannot connect', function () {
    noSecretsInErrorsFailConnections();

    $caught = null;

    try {
        app(StoreFacebookPages::class)->handle(noSecretsInErrorsUserToken());
    } catch (RuntimeException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(RuntimeException::class)
        ->and($caught->getMessage())->toStartWith('Could not extend the Facebook user token');

    noSecretsInErrorsAssertClean($caught->getMessage());
});

it('keeps secrets out of the exception StoreInstagramAccounts throws when the token extension cannot connect', function () {
    noSecretsInErrorsFailConnections();

    $caught = null;

    try {
        app(StoreInstagramAccounts::class)->handle(noSecretsInErrorsUserToken());
    } catch (RuntimeException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(RuntimeException::class)
        ->and($caught->getMessage())->toStartWith('Could not extend the Facebook user token');

    noSecretsInErrorsAssertClean($caught->getMessage());
});

it('keeps secrets out of the exception StoreAccountFromSocialite throws when the Threads exchange cannot connect', function () {
    noSecretsInErrorsFailConnections();

    $caught = null;

    try {
        app(StoreAccountFromSocialite::class)->handle('threads', socialiteUser([
            'id' => 'threads-user-1',
            'token' => noSecretsInErrorsThreadsToken(),
        ]));
    } catch (RuntimeException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(RuntimeException::class)
        ->and($caught->getMessage())->toStartWith('Could not obtain a long-lived [threads] token');

    noSecretsInErrorsAssertClean($caught->getMessage());
});

it('logs nothing that carries a secret while a queued renewal fails to connect', function () {
    Event::fake([CredentialNeedsReconnect::class]);
    noSecretsInErrorsFailConnections();

    // Keep the log out of the filesystem; MessageLogged still fires.
    config()->set('logging.default', 'null');

    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = $event->message.' '.json_encode($event->context);
    });

    $token = noSecretsInErrorsCredential('facebook', noSecretsInErrorsUserToken(), [
        'expires_at' => now()->subMinute(),
        'renew_at' => now()->subDay(),
    ]);

    $job = new RenewCredential($token);

    try {
        $job->handle(app(SocialTokens::class), app(ConnectorRegistry::class));
    } catch (RuntimeException $e) {
        // What Illuminate\Queue\Worker::runJob() does with a failed attempt:
        // report it through the exception handler, i.e. to laravel.log.
        report($e);
        $job->failed($e);
    }

    // The reported failure must be there, or the check below proves nothing.
    expect($logged)->not->toBeEmpty();

    foreach ($logged as $line) {
        noSecretsInErrorsAssertClean($line);
    }

    expect($token->fresh()->status)->toBe(AccountStatus::NeedsReconnect);
});

// Serialisation ---------------------------------------------------------------

it('hides access_token and refresh_token when a SocialToken is serialised', function () {
    $token = noSecretsInErrorsCredential('tiktok', 'tiktok-access-plain-81f2', [
        'refresh_token' => 'tiktok-refresh-plain-3b6c',
    ])->fresh();

    expect($token->toArray())
        ->not->toHaveKey('access_token')
        ->not->toHaveKey('refresh_token');

    expect($token->toJson())
        ->not->toContain('tiktok-access-plain-81f2')
        ->not->toContain('tiktok-refresh-plain-3b6c');
});

it('hides the credential tokens when accounts are serialised with their credential', function () {
    $token = noSecretsInErrorsCredential('tiktok', 'tiktok-access-plain-81f2', [
        'refresh_token' => 'tiktok-refresh-plain-3b6c',
    ]);

    SocialAccount::create([
        'provider' => 'tiktok',
        'provider_user_id' => 'tiktok-user-1',
        'social_token_id' => $token->getKey(),
        'status' => AccountStatus::Active,
    ]);

    // The listing pattern the README recommends.
    $json = SocialAccount::with('credential')->get()->toJson();

    expect($json)
        ->toContain('tiktok-user-1')
        ->not->toContain('tiktok-access-plain-81f2')
        ->not->toContain('tiktok-refresh-plain-3b6c');
});

it('keeps the tokens readable as attributes and the other credential fields serialisable', function () {
    $token = noSecretsInErrorsCredential('tiktok', 'tiktok-access-plain-81f2', [
        'refresh_token' => 'tiktok-refresh-plain-3b6c',
        'expires_at' => now()->addDay(),
    ])->fresh();

    expect($token->access_token)->toBe('tiktok-access-plain-81f2')
        ->and($token->refresh_token)->toBe('tiktok-refresh-plain-3b6c');

    expect($token->toArray())
        ->toHaveKeys(['id', 'provider', 'provider_holder_id', 'status', 'expires_at', 'renew_at']);

    // An app that really needs them serialised can still opt in.
    expect($token->makeVisible(['access_token', 'refresh_token'])->toArray())
        ->toMatchArray([
            'access_token' => 'tiktok-access-plain-81f2',
            'refresh_token' => 'tiktok-refresh-plain-3b6c',
        ]);
});
