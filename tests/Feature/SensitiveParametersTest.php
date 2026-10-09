<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Two\User as SocialiteUser;
use Pr4w\SocialTokens\Actions\StoreAccountFromSocialite;
use Pr4w\SocialTokens\Actions\StoreConnection;
use Pr4w\SocialTokens\Actions\StoreFacebookPages;
use Pr4w\SocialTokens\Actions\StoreInstagramAccounts;
use Pr4w\SocialTokens\Actions\StoreLinkedInOrganizations;
use Pr4w\SocialTokens\Connectors\AbstractConnector;
use Pr4w\SocialTokens\Connectors\FacebookConnector;
use Pr4w\SocialTokens\Connectors\InstagramConnector;
use Pr4w\SocialTokens\Connectors\LinkedInConnector;
use Pr4w\SocialTokens\Connectors\OAuth2Connector;
use Pr4w\SocialTokens\Connectors\ThreadsConnector;
use Pr4w\SocialTokens\Connectors\TikTokConnector;
use Pr4w\SocialTokens\Connectors\XConnector;
use Pr4w\SocialTokens\Contracts\ProviderConnector;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\RenewalResult;

/*
 * Exception traces record call arguments (zend.exception_ignore_args is Off
 * without a php.ini, as in the official Docker images), with each
 * parameter's CURRENT value. Error trackers that collect frame arguments
 * (Sentry's frame vars, Flare) would then store the long-lived user tokens
 * and refresh tokens the Store* actions were handling when they threw.
 * Every parameter carrying a token or a secret is #[\SensitiveParameter].
 */

beforeEach(function () {
    $this->ignoreArgs = ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '0');
});

afterEach(function () {
    ini_set('zend.exception_ignore_args', $this->ignoreArgs);
});

/**
 * The needles found in the argument values of every frame of $e's trace (and
 * its previous exceptions'). A Socialite user is looked into: it carries the
 * tokens, and a tracker serialising objects would send them.
 *
 * @param  array<int, string>  $needles
 * @return array<int, string>
 */
function sensitiveLeaksIn(Throwable $e, array $needles): array
{
    $found = [];

    $walk = function (mixed $value) use (&$walk, &$found, $needles): void {
        if (is_string($value)) {
            foreach ($needles as $needle) {
                if (str_contains($value, $needle)) {
                    $found[] = $needle;
                }
            }
        } elseif (is_array($value)) {
            array_walk($value, fn ($item) => $walk($item));
        } elseif ($value instanceof SocialiteUser) {
            $walk([$value->token, $value->refreshToken]);
        }
    };

    for ($current = $e; $current !== null; $current = $current->getPrevious()) {
        foreach ($current->getTrace() as $frame) {
            $walk($frame['args'] ?? []);
        }
    }

    return array_values(array_unique($found));
}

function sensitiveThrown(Closure $call): Throwable
{
    try {
        $call();
    } catch (Throwable $e) {
        return $e;
    }

    throw new RuntimeException('Expected the call to throw.');
}

/** Meta extends the token, then /me/accounts fails: the action throws holding the extended token. */
function sensitiveFailMetaListing(): void
{
    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'EAAG-LONG-LIVED-60DAY', 'expires_in' => 5183944]),
        'graph.facebook.com/*/me/accounts*' => Http::response(['error' => ['message' => 'Bad gateway']], 502),
    ]);
}

it('keeps the Meta user tokens out of the trace when listing Pages fails', function (string $action) {
    sensitiveFailMetaListing();

    $e = sensitiveThrown(fn () => app($action)->handle(userToken: 'EAAG-SHORT-LIVED', userId: 'fb-user'));

    expect($e)->toBeInstanceOf(RuntimeException::class)
        ->and($e->getMessage())->toContain('Could not list Facebook pages')
        ->and(sensitiveLeaksIn($e, ['EAAG-SHORT-LIVED', 'EAAG-LONG-LIVED-60DAY']))->toBe([]);
})->with([StoreFacebookPages::class, StoreInstagramAccounts::class]);

it('keeps the Socialite user and its tokens out of the trace of a failed connection', function () {
    sensitiveFailMetaListing();

    $user = socialiteUser(['id' => 'fb-user', 'token' => 'EAAG-SHORT-LIVED', 'refreshToken' => 'NO-REFRESH-EXPECTED']);

    $e = sensitiveThrown(fn () => app(StoreConnection::class)->handle('facebook', $user));

    expect(sensitiveLeaksIn($e, ['EAAG-SHORT-LIVED', 'EAAG-LONG-LIVED-60DAY', 'NO-REFRESH-EXPECTED']))->toBe([]);
});

it('keeps the Socialite user out of the trace when the long-lived exchange fails', function () {
    Http::fake(['graph.threads.net/*' => Http::response(['error' => ['message' => 'Bad gateway']], 502)]);

    $user = socialiteUser(['id' => 'th-user', 'token' => 'THQW-SHORT-LIVED', 'refreshToken' => 'THQW-REFRESH']);

    $e = sensitiveThrown(fn () => app(StoreAccountFromSocialite::class)->handle('threads', $user));

    expect($e->getMessage())->toContain('Could not obtain a long-lived')
        ->and(sensitiveLeaksIn($e, ['THQW-SHORT-LIVED', 'THQW-REFRESH']))->toBe([]);
});

it('keeps the LinkedIn member and refresh tokens out of the trace when listing organizations fails', function () {
    Http::fake(['api.linkedin.com/*' => Http::response([], 502)]);

    $e = sensitiveThrown(fn () => app(StoreLinkedInOrganizations::class)->handle(
        accessToken: 'AQV-LINKEDIN-MEMBER',
        memberId: 'member-1',
        refreshToken: 'AQW-LINKEDIN-REFRESH',
    ));

    expect($e->getMessage())->toContain('Could not list LinkedIn organizations')
        ->and(sensitiveLeaksIn($e, ['AQV-LINKEDIN-MEMBER', 'AQW-LINKEDIN-REFRESH']))->toBe([]);
});

/*
 * The #[\SensitiveParameter] attribute covers the package's own frames only.
 * The Store* actions used to hand the plaintext tokens to Eloquent's
 * updateOrCreate(), whose firstOrCreate(), createOrFirst() and create() frames
 * then carried them in the trace of a failed write (connection lost, deadlock,
 * disk error). The tokens are now cast, encrypted, before the write starts.
 */

/** Fail every write of the social_tokens row held by $holder, as a lost connection or a full disk would. */
function sensitiveFailTokenWrite(string $holder, string $on = 'INSERT'): void
{
    DB::statement("CREATE TRIGGER sensitive_fail_token_write BEFORE {$on} ON social_tokens WHEN NEW.provider_holder_id = '{$holder}' BEGIN SELECT RAISE(ABORT, 'disk I/O error'); END");
}

/** Meta extends the token and lists one Page, with its token and a linked Instagram account. */
function sensitiveMetaPages(): void
{
    Http::fake(function ($request) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/oauth/access_token') => Http::response(['access_token' => 'EAAG-LONG-LIVED-60DAY', 'expires_in' => 5183944]),
            str_contains($url, '/debug_token') => Http::response(['data' => ['scopes' => [], 'granular_scopes' => []]]),
            str_contains($url, '/me/accounts') => Http::response(['data' => [[
                'id' => 'page-1', 'name' => 'Page One', 'access_token' => 'EAAP-PAGE-TOKEN',
                'instagram_business_account' => ['id' => 'ig-1', 'username' => 'insta'],
            ]]]),
            default => Http::response([], 404),
        };
    });
}

it('keeps the Meta tokens out of the trace of a failed database write', function (string $action, string $holder) {
    sensitiveMetaPages();
    sensitiveFailTokenWrite($holder);

    $e = sensitiveThrown(fn () => app($action)->handle(userToken: 'EAAG-SHORT-LIVED', userId: 'fb-user'));

    expect($e)->toBeInstanceOf(QueryException::class)
        ->and(sensitiveLeaksIn($e, ['EAAG-SHORT-LIVED', 'EAAG-LONG-LIVED-60DAY', 'EAAP-PAGE-TOKEN']))->toBe([]);
})->with([
    'facebook user credential' => [StoreFacebookPages::class, 'fb-user'],
    'facebook page credential' => [StoreFacebookPages::class, 'page-1'],
    'instagram user credential' => [StoreInstagramAccounts::class, 'fb-user'],
    'instagram companion page credential' => [StoreInstagramAccounts::class, 'page-1'],
]);

it('keeps the Socialite tokens out of the trace of a failed database write', function (string $on) {
    if ($on === 'UPDATE') {
        SocialToken::create(['provider' => 'tiktok', 'provider_holder_id' => 'tt-user', 'access_token' => 'old', 'status' => 'active']);
    }

    sensitiveFailTokenWrite('tt-user', $on);

    $user = socialiteUser(['id' => 'tt-user', 'token' => 'ACT-TIKTOK-ACCESS', 'refreshToken' => 'RFT-TIKTOK-REFRESH']);

    $e = sensitiveThrown(fn () => app(StoreConnection::class)->handle('tiktok', $user));

    expect($e)->toBeInstanceOf(QueryException::class)
        ->and(sensitiveLeaksIn($e, ['ACT-TIKTOK-ACCESS', 'RFT-TIKTOK-REFRESH']))->toBe([]);
})->with(['INSERT', 'UPDATE']);

it('keeps the LinkedIn tokens out of the trace of a failed database write', function () {
    Http::fake(['api.linkedin.com/*' => Http::response(['elements' => []])]);
    sensitiveFailTokenWrite('member-1');

    $e = sensitiveThrown(fn () => app(StoreLinkedInOrganizations::class)->handle(
        accessToken: 'AQV-LINKEDIN-MEMBER',
        memberId: 'member-1',
        refreshToken: 'AQW-LINKEDIN-REFRESH',
    ));

    expect($e)->toBeInstanceOf(QueryException::class)
        ->and(sensitiveLeaksIn($e, ['AQV-LINKEDIN-MEMBER', 'AQW-LINKEDIN-REFRESH']))->toBe([]);
});

it('marks every parameter carrying a token or a secret as sensitive', function (string $class, string $method, string $parameter) {
    $found = collect((new ReflectionMethod($class, $method))->getParameters())
        ->firstWhere(fn (ReflectionParameter $candidate) => $candidate->getName() === $parameter);

    expect($found)->not->toBeNull("{$class}::{$method}() has no \${$parameter}")
        ->and($found->getAttributes(SensitiveParameter::class))->not->toBeEmpty("{$class}::{$method}(\${$parameter}) is not #[\\SensitiveParameter]");
})->with([
    [StoreConnection::class, 'handle', 'user'],
    [StoreAccountFromSocialite::class, 'handle', 'user'],
    [StoreFacebookPages::class, 'handle', 'userToken'],
    [StoreInstagramAccounts::class, 'handle', 'userToken'],
    [StoreLinkedInOrganizations::class, 'handle', 'accessToken'],
    [StoreLinkedInOrganizations::class, 'handle', 'refreshToken'],
    [ProviderConnector::class, 'exchangeForLongLived', 'accessToken'],
    [AbstractConnector::class, 'exchangeForLongLived', 'accessToken'],
    [AbstractConnector::class, 'redactQueryStrings', 'message'],
    [OAuth2Connector::class, 'refreshParameters', 'refreshToken'],
    [TikTokConnector::class, 'refreshParameters', 'refreshToken'],
    [XConnector::class, 'refreshParameters', 'refreshToken'],
    [FacebookConnector::class, 'extendUserToken', 'userToken'],
    [FacebookConnector::class, 'grantedScopesByAccount', 'userToken'],
    [FacebookConnector::class, 'fetchUserId', 'userToken'],
    [FacebookConnector::class, 'fetchMe', 'token'],
    [FacebookConnector::class, 'fetchPages', 'userToken'],
    [FacebookConnector::class, 'appSecretProof', 'accessToken'],
    [FacebookConnector::class, 'appSecretProofQuery', 'accessToken'],
    [FacebookConnector::class, 'withAppSecretProof', 'accessToken'],
    [InstagramConnector::class, 'appSecretProof', 'accessToken'],
    [InstagramConnector::class, 'exchangeForLongLived', 'accessToken'],
    [InstagramConnector::class, 'extend', 'accessToken'],
    [ThreadsConnector::class, 'exchangeForLongLived', 'accessToken'],
    [ThreadsConnector::class, 'refreshWithToken', 'accessToken'],
    [LinkedInConnector::class, 'fetchOrganizations', 'accessToken'],
    [SocialToken::class, 'markNeedsReconnectOnce', 'checkedToken'],
    [SocialTokens::class, 'reportRejected', 'rejectedToken'],
    [RenewalResult::class, 'success', 'accessToken'],
    [RenewalResult::class, 'success', 'refreshToken'],
    [RenewalResult::class, '__construct', 'accessToken'],
    [RenewalResult::class, '__construct', 'refreshToken'],
]);
