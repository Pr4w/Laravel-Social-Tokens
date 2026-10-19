<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Contracts\Factory;
use Laravel\Socialite\Facades\Socialite;
use Pr4w\SocialTokens\Actions\StoreConnection;
use Pr4w\SocialTokens\Connectors\PinterestConnector;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Socialite\BlueskyProvider;
use Pr4w\SocialTokens\Socialite\PinterestProvider;
use Pr4w\SocialTokens\Socialite\PinterestUser;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\SocialTokensServiceProvider;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

/*
 * Pinterest API v5 (OAuth 2.0, the package's `pinterest` driver): 30-day
 * access tokens, a 60-day continuous refresh token that every renewal
 * replaces, a client authenticated with HTTP Basic, errors as {code, message},
 * and a Sandbox with tokens of its own for apps with Trial access.
 */

function pinterest(): PinterestConnector
{
    return app(ConnectorRegistry::class)->for('pinterest');
}

function pinterestCredential(array $attrs = []): SocialToken
{
    return SocialToken::create(array_merge([
        'provider' => 'pinterest',
        'provider_holder_id' => '1151643592048765432',
        'access_token' => 'pina-access-1',
        'refresh_token' => 'pinr-refresh-1',
        'status' => AccountStatus::Active,
        'expires_at' => now()->addDays(2),
        'renew_at' => now()->subMinutes(10),
    ], $attrs));
}

function pinterestTokenAnswers(int $status, array $body, string $host = 'api.pinterest.com'): void
{
    Http::fake(["{$host}/v5/oauth/token" => Http::response($body, $status)]);
}

/** A successful refresh answer, as Pinterest documents it. */
function pinterestRefreshed(array $overrides = []): array
{
    return array_merge([
        'access_token' => 'pina-access-2',
        'response_type' => 'refresh_token',
        'token_type' => 'bearer',
        'expires_in' => 2592000,
        'scope' => 'boards:read boards:write pins:read pins:write user_accounts:read',
        'refresh_token' => 'pinr-refresh-2',
        'refresh_token_expires_in' => 5184000,
        'refresh_token_expires_at' => now()->addDays(60)->timestamp,
    ], $overrides);
}

// Renewal ------------------------------------------------------------------

it('is a rotating refresh-token provider renewed three days ahead, on the production API', function () {
    expect(pinterest()->renewalStrategy())->toBe(RenewalStrategy::RotatingRefreshToken)
        ->and(pinterest()->leadTime()->totalDays)->toBe(3.0)
        ->and(pinterest()->credentialProvider())->toBe('pinterest')
        ->and(pinterest()->sandbox())->toBeFalse()
        ->and(pinterest()->apiUrl())->toBe('https://api.pinterest.com/v5/');
});

it('refreshes with HTTP Basic client authentication and only the grant in the body', function () {
    pinterestTokenAnswers(200, pinterestRefreshed());

    $result = pinterest()->refreshCredential(pinterestCredential());

    expect($result->succeeded())->toBeTrue()
        ->and($result->accessToken)->toBe('pina-access-2')
        ->and($result->refreshToken)->toBe('pinr-refresh-2') // rotated: must be stored
        ->and($result->expiresAt->timestamp)->toEqualWithDelta(now()->addDays(30)->timestamp, 2)
        ->and($result->refreshExpiresAt->timestamp)->toEqualWithDelta(now()->addDays(60)->timestamp, 2)
        ->and($result->profile)->toBe(['scope' => 'boards:read boards:write pins:read pins:write user_accounts:read']);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.pinterest.com/v5/oauth/token'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('pinterest-id:pinterest-secret'))
        && $request->data() === ['grant_type' => 'refresh_token', 'refresh_token' => 'pinr-refresh-1']);
});

it('renews end to end and stores the new refresh token and its expiry', function () {
    pinterestTokenAnswers(201, pinterestRefreshed()); // the spec allows 200 or 201
    $token = pinterestCredential();

    expect(app(SocialTokens::class)->renewCredential($token)->succeeded())->toBeTrue();

    $fresh = $token->fresh();

    expect($fresh->access_token)->toBe('pina-access-2')
        ->and($fresh->refresh_token)->toBe('pinr-refresh-2')
        ->and($fresh->refresh_expires_at->timestamp)->toEqualWithDelta(now()->addDays(60)->timestamp, 2)
        ->and($fresh->scopes)->toBe(['boards:read', 'boards:write', 'pins:read', 'pins:write', 'user_accounts:read'])
        ->and($fresh->renew_at->timestamp)->toEqualWithDelta(now()->addDays(27)->timestamp, 2);
});

it('renews at the Sandbox when the app works there', function (array $config) {
    foreach ($config as $key => $value) {
        config()->set($key, $value);
    }
    app()->forgetInstance(ConnectorRegistry::class);
    pinterestTokenAnswers(200, pinterestRefreshed(), 'api-sandbox.pinterest.com');

    expect(pinterest()->sandbox())->toBeTrue()
        ->and(pinterest()->apiUrl())->toBe('https://api-sandbox.pinterest.com/v5/')
        ->and(pinterest()->refreshCredential(pinterestCredential())->succeeded())->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api-sandbox.pinterest.com/v5/oauth/token');
})->with([
    'from services.pinterest' => [['services.pinterest.sandbox' => true]],
    'from the connector' => [['social-tokens.connectors.pinterest.sandbox' => true]],
]);

it('reads the sandbox option as every boolean setting', function (mixed $value, bool $sandbox) {
    config()->set('services.pinterest.sandbox', $value);
    app()->forgetInstance(ConnectorRegistry::class);

    expect(pinterest()->sandbox())->toBe($sandbox)
        ->and(pinterest()->apiUrl())->toBe($sandbox ? 'https://api-sandbox.pinterest.com/v5/' : 'https://api.pinterest.com/v5/');
})->with([
    // env() returns PINTEREST_SANDBOX=off as the string 'off', which a cast took for true
    'off' => ['off', false],
    'no' => ['no', false],
    'the string false' => ['false', false],
    '0' => ['0', false],
    'empty' => ['', false],
    'unset' => [null, false],
    'on' => ['on', true],
    'true' => [true, true],
    '1' => ['1', true],
]);

it('keeps a pinterest driver the app registered before the package', function () {
    $socialite = app(Factory::class);
    $socialite->extend('pinterest', fn () => 'the app\'s own driver');
    Socialite::forgetDrivers();

    // The package's registration runs again, as when it boots after the app's
    $provider = app()->getProvider(SocialTokensServiceProvider::class);
    (fn () => $this->registerSocialiteDrivers())->call($provider);

    expect(Socialite::driver('pinterest'))->toBe('the app\'s own driver')
        ->and(Socialite::driver('bluesky'))->toBeInstanceOf(BlueskyProvider::class);
});

it('treats an answer about the refresh token as a dead grant', function () {
    pinterestTokenAnswers(400, ['code' => 1, 'message' => 'Invalid refresh token.']);

    $result = pinterest()->refreshCredential(pinterestCredential());

    expect($result->outcome)->toBe(RenewalOutcome::Terminal)
        ->and($result->reason)->toBe('1: Invalid refresh token.')
        ->and($result->context)->toMatchArray(['error' => '1', 'status' => 400]);
});

it('treats a refused client as app-side, never as a dead grant', function () {
    // What the token endpoint answers when the client's Basic credentials fail.
    pinterestTokenAnswers(401, ['code' => 2, 'message' => 'Authentication failed.']);

    $result = pinterest()->refreshCredential(pinterestCredential());

    expect($result->outcome)->toBe(RenewalOutcome::Transient)
        ->and($result->clientError)->toBeTrue()
        ->and($result->reason)->toBe('2: Authentication failed.');
});

it('retries and logs an error it does not know yet', function () {
    pinterestTokenAnswers(400, ['code' => 3, 'message' => 'Parameter is invalid.']);

    $result = pinterest()->refreshCredential(pinterestCredential());

    expect($result->outcome)->toBe(RenewalOutcome::Transient)
        ->and($result->unknown)->toBeTrue()
        ->and($result->clientError)->toBeFalse()
        ->and($result->reason)->toBe('3: Parameter is invalid.');
});

it('retries rate limits and outages', function (int $status, array $body) {
    pinterestTokenAnswers($status, $body);

    $result = pinterest()->refreshCredential(pinterestCredential());

    expect($result->outcome)->toBe(RenewalOutcome::Transient)
        ->and($result->clientError)->toBeFalse();
})->with([
    'rate limited' => [429, ['code' => 8, 'message' => 'You have exceeded your rate limit. Try again later.']],
    'outage' => [500, ['code' => 12, 'message' => 'Something went wrong on our end. Sorry about that.']],
]);

it('flags a credential without a refresh token without calling Pinterest', function () {
    Http::fake();

    $result = pinterest()->refreshCredential(pinterestCredential(['refresh_token' => null]));

    expect($result->outcome)->toBe(RenewalOutcome::Terminal);

    Http::assertNothingSent();
});

it('revokes nothing at Pinterest, which has no revocation for members', function () {
    Http::fake();

    pinterest()->revoke(pinterestCredential());

    Http::assertNothingSent();
});

// Checking a token ---------------------------------------------------------

it('checks a token with GET /user_account', function () {
    Http::fake(['api.pinterest.com/v5/user_account' => Http::response(['id' => '1151643592048765432', 'username' => 'alice'])]);

    $result = pinterest()->checkCredential(pinterestCredential());

    expect($result->succeeded())->toBeTrue()
        ->and($result->accessToken)->toBe('pina-access-1');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.pinterest.com/v5/user_account'
        && $request->hasHeader('Authorization', 'Bearer pina-access-1'));
});

it('takes a rejected token within its 30 days as dead, and an expired one as the renewal\'s job', function (string $expiresAt, RenewalOutcome $outcome) {
    Http::fake(['api.pinterest.com/v5/user_account' => Http::response(['code' => 2, 'message' => 'Authentication failed.', 'status' => 'failure'], 401)]);

    $result = pinterest()->checkCredential(pinterestCredential(['expires_at' => now()->modify($expiresAt)]));

    expect($result->outcome)->toBe($outcome)
        ->and($result->context['definitive'] ?? false)->toBeFalse() // a 401 alone flags on the second strike
        ->and($result->context)->toMatchArray(['status' => 401, 'code' => 2]);
})->with([
    'within its 30 days' => ['+20 days', RenewalOutcome::Terminal],
    'past them' => ['-1 hour', RenewalOutcome::Transient],
]);

it('does not judge a token on another answer', function (int $status, bool $unknown) {
    Http::fake(['api.pinterest.com/v5/user_account' => Http::response(['code' => 29, 'message' => 'Forbidden.'], $status)]);

    $result = pinterest()->checkCredential(pinterestCredential());

    expect($result->outcome)->toBe(RenewalOutcome::Transient)
        ->and($result->unknown)->toBe($unknown);
})->with([
    'forbidden' => [403, true],
    'outage' => [503, false],
]);

it('records a strike, not a verdict, when a publish reports a 401', function () {
    $account = app(StoreConnection::class)->handle('pinterest', socialiteUser(['id' => '1151643592048765432', 'token' => 'pina-access-1', 'refreshToken' => 'pinr-refresh-1', 'expiresIn' => 2592000]))->sole();
    Http::fake(['api.pinterest.com/v5/user_account' => Http::response(['code' => 2, 'message' => 'Authentication failed.'], 401)]);

    $flagged = app(SocialTokens::class)->reportRejected($account, 'Pin creation answered 401', terminal: false);

    expect($flagged)->toBeFalse()
        ->and($account->credential->fresh()->failed_checks)->toBe(1)
        ->and($account->credential->fresh()->status)->toBe(AccountStatus::Active);
});

// Connecting ---------------------------------------------------------------

function pinterestCallback(): PinterestUser
{
    $session = app('session')->driver('array');
    $request = HttpRequest::create('/');
    $request->setLaravelSession($session);
    app()->instance('request', $request);
    Socialite::driver('pinterest')->redirect();

    $callback = HttpRequest::create('/callback', 'GET', ['code' => 'code-1', 'state' => $session->get('state')]);
    $callback->setLaravelSession($session);
    app()->instance('request', $callback);
    Socialite::forgetDrivers();

    return Socialite::driver('pinterest')->user();
}

function pinterestConnectAnswers(string $host = 'api.pinterest.com', array $account = []): void
{
    Http::fake([
        "{$host}/v5/oauth/token" => Http::response([
            'access_token' => 'pina-access-1',
            'refresh_token' => 'pinr-refresh-1',
            'response_type' => 'authorization_code',
            'token_type' => 'bearer',
            'expires_in' => 2592000,
            'refresh_token_expires_in' => 5184000,
            'scope' => 'boards:read boards:write pins:read pins:write user_accounts:read',
        ]),
        "{$host}/v5/user_account" => Http::response(array_merge([
            'id' => '1151643592048765432',
            'username' => 'alice',
            'account_type' => 'BUSINESS',
            'business_name' => 'Alice Bakes',
            'profile_image' => 'https://i.pinimg.com/alice.jpg',
            'follower_count' => 12,
        ], $account)),
    ]);
}

it('sends the member to Pinterest with comma-separated scopes', function () {
    $this->app->instance('request', $request = HttpRequest::create('/'));
    $request->setLaravelSession($this->app['session']->driver('array'));

    $url = Socialite::driver('pinterest')->scopes(['boards:read', 'pins:write'])->redirect()->getTargetUrl();
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect(Socialite::driver('pinterest'))->toBeInstanceOf(PinterestProvider::class)
        ->and(strtok($url, '?'))->toBe('https://www.pinterest.com/oauth/')
        ->and($query)->toMatchArray([
            'client_id' => 'pinterest-id',
            'redirect_uri' => 'https://app.test/oauth/pinterest/callback',
            'response_type' => 'code',
            'scope' => 'user_accounts:read,boards:read,pins:write',
        ])
        ->and($query['state'])->toBe($request->session()->get('state'));
});

it('exchanges the code with HTTP Basic and identifies the account by its id', function () {
    pinterestConnectAnswers();

    $user = pinterestCallback();

    expect($user)->toBeInstanceOf(PinterestUser::class)
        ->and($user->getId())->toBe('1151643592048765432')
        ->and($user->getNickname())->toBe('alice')
        ->and($user->getName())->toBe('Alice Bakes')
        ->and($user->getAvatar())->toBe('https://i.pinimg.com/alice.jpg')
        ->and($user->getRaw()['account_type'])->toBe('BUSINESS')
        ->and($user->approvedScopes)->toBe(['boards:read', 'boards:write', 'pins:read', 'pins:write', 'user_accounts:read']);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.pinterest.com/v5/oauth/token'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('pinterest-id:pinterest-secret'))
        && $request->data() === ['grant_type' => 'authorization_code', 'code' => 'code-1', 'redirect_uri' => 'https://app.test/oauth/pinterest/callback']);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.pinterest.com/v5/user_account'
        && $request->hasHeader('Authorization', 'Bearer pina-access-1'));
});

it('stores the connection with both token lifetimes', function () {
    pinterestConnectAnswers();

    $account = app(StoreConnection::class)->handle('pinterest', pinterestCallback())->sole();
    $credential = $account->credential;

    expect($account->provider_user_id)->toBe('1151643592048765432')
        ->and($account->nickname)->toBe('alice')
        ->and($credential->refresh_token)->toBe('pinr-refresh-1')
        ->and($credential->expires_at->timestamp)->toEqualWithDelta(now()->addDays(30)->timestamp, 2)
        ->and($credential->refresh_expires_at->timestamp)->toEqualWithDelta(now()->addDays(60)->timestamp, 2)
        ->and($credential->renew_at->timestamp)->toEqualWithDelta(now()->addDays(27)->timestamp, 2);
});

it('names a personal account by its username', function () {
    pinterestConnectAnswers(account: ['account_type' => 'PINNER', 'business_name' => null]);

    expect(pinterestCallback()->getName())->toBe('alice');
});

it('connects at the Sandbox when the app works there', function () {
    config()->set('services.pinterest.sandbox', true);
    app()->forgetInstance(ConnectorRegistry::class);
    pinterestConnectAnswers('api-sandbox.pinterest.com');

    expect(pinterestCallback()->getId())->toBe('1151643592048765432');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api-sandbox.pinterest.com/v5/oauth/token');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://api-sandbox.pinterest.com/v5/user_account');
});

it('fails loudly when Pinterest refuses the code or omits the account id', function (array $answers, string $message) {
    Http::fake(collect($answers)->map(fn (array $answer) => Http::response($answer[1], $answer[0]))->all());

    expect(fn () => pinterestCallback())->toThrow(RuntimeException::class, $message);
})->with([
    'refused code' => [
        ['api.pinterest.com/v5/oauth/token' => [400, ['code' => 1, 'message' => 'Invalid authorization code.']]],
        'Pinterest refused the authorization code (HTTP 400, code 1: Invalid authorization code.)',
    ],
    'no account id' => [
        [
            'api.pinterest.com/v5/oauth/token' => [200, ['access_token' => 'pina-access-1', 'expires_in' => 2592000]],
            'api.pinterest.com/v5/user_account' => [200, ['username' => 'alice']],
        ],
        'Pinterest returned the account without its id.',
    ],
]);
