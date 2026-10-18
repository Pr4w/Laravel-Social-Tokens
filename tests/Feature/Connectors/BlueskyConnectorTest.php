<?php

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Pr4w\SocialTokens\Actions\StoreConnection;
use Pr4w\SocialTokens\Connectors\BlueskyConnector;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Socialite\BlueskyProvider;
use Pr4w\SocialTokens\Socialite\BlueskyUser;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\Atproto;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Pr4w\SocialTokens\Support\DPoP;

/*
 * Bluesky through AT Protocol OAuth: PAR + PKCE + DPoP, a confidential client
 * authenticated with private_key_jwt, per-account authorization servers,
 * minutes-long access tokens and single-use rotating refresh tokens.
 */

const BSKY_ISSUER = 'https://bsky.social';
const BSKY_PDS = 'https://morel.us-east.host.bsky.network';
const BSKY_DID = 'did:plc:abc123';

beforeEach(function () {
    config([
        'services.bluesky.client_id' => 'https://app.test/oauth/bluesky/client-metadata.json',
        'services.bluesky.redirect' => 'https://app.test/oauth/bluesky/callback',
        'services.bluesky.private_key' => $this->clientKey = DPoP::generateKey(),
        'services.bluesky.client_name' => 'NotionScheduler',
    ]);
});

function bluesky(): BlueskyConnector
{
    return app(ConnectorRegistry::class)->for('bluesky');
}

/** The claims of a compact JWS, after checking its ES256 signature against $jwk. */
function verifiedClaims(string $jws, ?array $jwk = null): array
{
    [$header, $payload, $signature] = explode('.', $jws);
    $decode = fn (string $part) => base64_decode(strtr($part, '-_', '+/'));
    $headerData = json_decode($decode($header), true);
    $jwk ??= $headerData['jwk'];

    // raw R || S → DER, and the JWK → a PEM public key, for openssl_verify
    $raw = $decode($signature);
    $int = fn (string $bytes) => "\x02".chr(strlen($bytes = (ord($bytes[0]) & 0x80 ? "\0" : '').ltrim($bytes, "\0") ?: "\0")).$bytes;
    $sequence = $int(substr($raw, 0, 32)).$int(substr($raw, 32));
    $der = "\x30".chr(strlen($sequence)).$sequence;

    $point = "\x04".$decode($jwk['x']).$decode($jwk['y']);
    $spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$point;
    $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64)."-----END PUBLIC KEY-----\n";

    expect(openssl_verify("{$header}.{$payload}", $der, $pem, OPENSSL_ALGO_SHA256))->toBe(1);

    return ['header' => $headerData, 'claims' => json_decode($decode($payload), true)];
}

function blueskyCredential(array $attrs = []): SocialToken
{
    return SocialToken::create(array_merge([
        'provider' => 'bluesky',
        'provider_holder_id' => BSKY_DID,
        'access_token' => 'bsky-access-1',
        'refresh_token' => 'bsky-refresh-1',
        'dpop_key' => DPoP::generateKey(),
        'metadata' => [
            'issuer' => BSKY_ISSUER,
            'token_endpoint' => BSKY_ISSUER.'/oauth/token',
            'revocation_endpoint' => BSKY_ISSUER.'/oauth/revoke',
            'pds' => BSKY_PDS,
        ],
        'status' => AccountStatus::Active,
        'expires_at' => now()->addMinutes(1),
        'renew_at' => now()->subMinute(),
    ], $attrs));
}

function fakeBlueskyDiscovery(array $extra = []): void
{
    Http::fake($extra + [
        BSKY_ISSUER.'/xrpc/com.atproto.identity.resolveHandle*' => Http::response(['did' => BSKY_DID]),
        'plc.directory/'.BSKY_DID => Http::response([
            'id' => BSKY_DID,
            'alsoKnownAs' => ['at://alice.bsky.social'],
            'service' => [['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => BSKY_PDS]],
        ]),
        BSKY_PDS.'/.well-known/oauth-protected-resource' => Http::response(['authorization_servers' => [BSKY_ISSUER]]),
        BSKY_ISSUER.'/.well-known/oauth-protected-resource' => Http::response(['authorization_servers' => [BSKY_ISSUER]]),
        BSKY_ISSUER.'/.well-known/oauth-authorization-server' => Http::response([
            'issuer' => BSKY_ISSUER,
            'authorization_endpoint' => BSKY_ISSUER.'/oauth/authorize',
            'token_endpoint' => BSKY_ISSUER.'/oauth/token',
            'pushed_authorization_request_endpoint' => BSKY_ISSUER.'/oauth/par',
            'revocation_endpoint' => BSKY_ISSUER.'/oauth/revoke',
        ]),
        'public.api.bsky.app/xrpc/app.bsky.actor.getProfile*' => Http::response([
            'did' => BSKY_DID, 'handle' => 'alice.bsky.social', 'displayName' => 'Alice', 'avatar' => 'https://cdn.bsky.app/a.jpg',
        ]),
    ]);
}

// DPoP --------------------------------------------------------------------

it('signs DPoP proofs that verify against the key they carry', function () {
    $key = DPoP::generateKey();
    $proof = verifiedClaims(DPoP::proof($key, 'post', 'https://pds.test/xrpc/x?y=1#z', 'nonce-1', 'tok'));

    expect($proof['header'])->toMatchArray(['typ' => 'dpop+jwt', 'alg' => 'ES256', 'jwk' => DPoP::publicJwk($key)])
        ->and($proof['claims'])->toMatchArray([
            'htm' => 'POST',
            'htu' => 'https://pds.test/xrpc/x',
            'nonce' => 'nonce-1',
            'ath' => DPoP::base64url(hash('sha256', 'tok', true)),
        ])
        ->and($proof['claims'])->toHaveKeys(['jti', 'iat']);
});

it('retries once with the nonce the server asks for, and remembers it', function () {
    $key = DPoP::generateKey();
    $proofs = [];

    Http::fake(function (Request $request) use (&$proofs) {
        $proofs[] = verifiedClaims($request->header('DPoP')[0])['claims'];

        return count($proofs) === 1
            ? Http::response(['error' => 'use_dpop_nonce'], 400, ['DPoP-Nonce' => 'n-1'])
            : Http::response(['ok' => true], 200, ['DPoP-Nonce' => 'n-2']);
    });

    $response = Http::withMiddleware(DPoP::middleware($key))->asForm()->post('https://auth.test/oauth/token', ['a' => 'b']);

    expect($response->json('ok'))->toBeTrue()
        ->and($proofs)->toHaveCount(2)
        ->and($proofs[0])->not->toHaveKey('nonce')
        ->and($proofs[1]['nonce'])->toBe('n-1')
        ->and(DPoP::nonce('https://auth.test/anything'))->toBe('n-2');
});

it('reads a resource server nonce request from WWW-Authenticate', function () {
    expect(DPoP::asksForNonce(new Psr7Response(401, ['DPoP-Nonce' => 'n', 'WWW-Authenticate' => 'DPoP error="use_dpop_nonce"'])))->toBeTrue()
        ->and(DPoP::asksForNonce(new Psr7Response(401, ['WWW-Authenticate' => 'DPoP error="invalid_token"'])))->toBeFalse();
});

// Client ------------------------------------------------------------------

it('describes the confidential client for its metadata document and JWKS', function () {
    $metadata = bluesky()->clientMetadata();

    expect(bluesky()->isConfidential())->toBeTrue()
        ->and($metadata)->toMatchArray([
            'client_id' => 'https://app.test/oauth/bluesky/client-metadata.json',
            'client_name' => 'NotionScheduler',
            'redirect_uris' => ['https://app.test/oauth/bluesky/callback'],
            'scope' => 'atproto transition:generic',
            'dpop_bound_access_tokens' => true,
            'token_endpoint_auth_method' => 'private_key_jwt',
            'token_endpoint_auth_signing_alg' => 'ES256',
        ])
        ->and($metadata['jwks']['keys'][0])->toMatchArray(DPoP::publicJwk($this->clientKey) + ['kid' => 'k1', 'alg' => 'ES256']);
});

it('falls back to the loopback client without a client metadata URL', function () {
    config(['services.bluesky.client_id' => null, 'services.bluesky.redirect' => 'http://127.0.0.1:8123/oauth/bluesky/callback']);

    expect(bluesky()->isConfidential())->toBeFalse()
        ->and(bluesky()->clientId())->toBe('http://localhost?redirect_uri=http%3A%2F%2F127.0.0.1%3A8123%2Foauth%2Fbluesky%2Fcallback&scope=atproto%20transition%3Ageneric')
        ->and(bluesky()->clientAuthentication(BSKY_ISSUER))->toBe(['client_id' => bluesky()->clientId()]);
});

it('authenticates with a client assertion signed by the client key, for one issuer', function () {
    $auth = bluesky()->clientAuthentication(BSKY_ISSUER);
    $assertion = verifiedClaims($auth['client_assertion'], DPoP::publicJwk($this->clientKey));

    expect($auth['client_assertion_type'])->toBe('urn:ietf:params:oauth:client-assertion-type:jwt-bearer')
        ->and($assertion['header']['kid'])->toBe('k1')
        ->and($assertion['claims'])->toMatchArray([
            'iss' => bluesky()->clientId(),
            'sub' => bluesky()->clientId(),
            'aud' => BSKY_ISSUER,
        ]);
});

// Renewal -----------------------------------------------------------------

it('is a rotating refresh-token provider kept alive daily, its access tokens renewed on demand', function () {
    $token = SocialToken::persistCredential(
        ['provider' => 'bluesky', 'provider_holder_id' => BSKY_DID],
        ['access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now()->addMinutes(15)],
        bluesky(),
    );

    expect(bluesky()->renewalStrategy())->toBe(RenewalStrategy::RotatingRefreshToken)
        ->and($token->renew_at->diffInHours(now()->addDay(), true))->toBeLessThan(1);
});

it('refreshes at the stored token endpoint, DPoP-signed with the credential key', function () {
    $token = blueskyCredential();

    Http::fake([BSKY_ISSUER.'/oauth/token' => Http::response([
        'access_token' => 'bsky-access-2',
        'refresh_token' => 'bsky-refresh-2',
        'token_type' => 'DPoP',
        'expires_in' => 900,
        'scope' => 'atproto transition:generic',
        'sub' => BSKY_DID,
    ])]);

    $result = bluesky()->refreshCredential($token);

    expect($result->succeeded())->toBeTrue()
        ->and($result->accessToken)->toBe('bsky-access-2')
        ->and($result->refreshToken)->toBe('bsky-refresh-2');

    Http::assertSent(function (Request $request) use ($token) {
        $proof = verifiedClaims($request->header('DPoP')[0]);
        verifiedClaims($request['client_assertion'], DPoP::publicJwk($this->clientKey));

        return $proof['header']['jwk'] === DPoP::publicJwk($token->dpop_key)
            && $proof['claims']['htu'] === BSKY_ISSUER.'/oauth/token'
            && ! isset($proof['claims']['ath'])
            && $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'bsky-refresh-1';
    });
});

it('flags a dead refresh token, and a credential without its DPoP key, for reconnection', function () {
    Http::fake([BSKY_ISSUER.'/oauth/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'refresh token replayed'], 400)]);

    expect(bluesky()->refreshCredential(blueskyCredential())->outcome)->toBe(RenewalOutcome::Terminal)
        ->and(bluesky()->refreshCredential(blueskyCredential(['provider_holder_id' => 'did:plc:other', 'dpop_key' => null]))->outcome)->toBe(RenewalOutcome::Terminal);
});

it('revokes the refresh token at the account\'s server, and forgets the DPoP key', function () {
    Http::fake([BSKY_ISSUER.'/oauth/revoke' => Http::response()]);
    $token = blueskyCredential();
    SocialAccount::create(['provider' => 'bluesky', 'provider_user_id' => BSKY_DID, 'social_token_id' => $token->id]);

    app(SocialTokens::class)->revoke($token);

    Http::assertSent(fn (Request $request) => $request->url() === BSKY_ISSUER.'/oauth/revoke' && $request['token'] === 'bsky-refresh-1');
    expect($token->fresh()->dpop_key)->toBeNull();
});

// Connect -----------------------------------------------------------------

it('pushes the authorization request and sends the user to the server with its request_uri', function () {
    fakeBlueskyDiscovery([BSKY_ISSUER.'/oauth/par' => Http::response(['request_uri' => 'urn:ietf:params:oauth:request_uri:req-1', 'expires_in' => 299], 201)]);
    $this->app->instance('request', $request = HttpRequest::create('/'));
    $request->setLaravelSession($this->app['session']->driver('array'));

    $redirect = Socialite::driver('bluesky')->with(['login_hint' => 'alice.bsky.social'])->redirect();

    expect(Socialite::driver('bluesky'))->toBeInstanceOf(BlueskyProvider::class)
        ->and($redirect->getTargetUrl())->toBe(BSKY_ISSUER.'/oauth/authorize?client_id='.rawurlencode(bluesky()->clientId()).'&request_uri='.rawurlencode('urn:ietf:params:oauth:request_uri:req-1'));

    Http::assertSent(fn (Request $request) => $request->url() === BSKY_ISSUER.'/oauth/par'
        && $request['login_hint'] === 'alice.bsky.social'
        && $request['code_challenge_method'] === 'S256'
        && $request['scope'] === 'atproto transition:generic'
        && $request['redirect_uri'] === 'https://app.test/oauth/bluesky/callback'
        && filled($request['client_assertion'])
        && $request->hasHeader('DPoP'));
});

it('exchanges the code, checks the account belongs to that server, and stores the DPoP key with the credential', function () {
    fakeBlueskyDiscovery([
        BSKY_ISSUER.'/oauth/par' => Http::response(['request_uri' => 'urn:req'], 201),
        BSKY_ISSUER.'/oauth/token' => Http::response([
            'access_token' => 'at-1', 'refresh_token' => 'rt-1', 'token_type' => 'DPoP', 'expires_in' => 900,
            'scope' => 'atproto transition:generic', 'sub' => BSKY_DID,
        ]),
    ]);

    $session = $this->app['session']->driver('array');
    $request = HttpRequest::create('/');
    $request->setLaravelSession($session);
    $this->app->instance('request', $request);
    Socialite::driver('bluesky')->redirect();
    $state = $session->get('state');

    $callback = HttpRequest::create('/callback', 'GET', ['code' => 'code-1', 'state' => $state, 'iss' => BSKY_ISSUER]);
    $callback->setLaravelSession($session);
    $this->app->instance('request', $callback);
    Socialite::forgetDrivers();

    $user = Socialite::driver('bluesky')->user();

    expect($user)->toBeInstanceOf(BlueskyUser::class)
        ->and($user->getId())->toBe(BSKY_DID)
        ->and($user->getNickname())->toBe('alice.bsky.social')
        ->and($user->getName())->toBe('Alice')
        ->and($user->pds())->toBe(BSKY_PDS)
        ->and($user->approvedScopes)->toBe(['atproto', 'transition:generic']);

    $account = app(StoreConnection::class)->handle('bluesky', $user)->sole();
    $credential = $account->credential;

    expect($credential->dpop_key)->toBe($user->dpopKey)
        ->and($credential->metadata)->toMatchArray(['issuer' => BSKY_ISSUER, 'token_endpoint' => BSKY_ISSUER.'/oauth/token', 'pds' => BSKY_PDS])
        ->and($credential->toArray())->not->toHaveKey('dpop_key')
        ->and($credential->getRawOriginal('dpop_key'))->not->toContain('PRIVATE KEY');
});

it('refuses a callback for an unknown state or from another server', function () {
    $request = HttpRequest::create('/callback', 'GET', ['code' => 'c', 'state' => 'nope', 'iss' => BSKY_ISSUER]);
    $request->setLaravelSession($this->app['session']->driver('array'));
    $this->app->instance('request', $request);

    expect(fn () => Socialite::driver('bluesky')->stateless()->user())->toThrow(InvalidStateException::class);
});

// Posting -----------------------------------------------------------------

it('hands out DPoP-signed requests for a Bluesky account, Bearer ones for the others', function () {
    $token = blueskyCredential(['expires_at' => now()->addMinutes(10)]);
    $account = SocialAccount::create(['provider' => 'bluesky', 'provider_user_id' => BSKY_DID, 'social_token_id' => $token->id, 'status' => AccountStatus::Active]);

    Http::fake([BSKY_PDS.'/*' => Http::response(['uri' => 'at://x'])]);

    app(SocialTokens::class)->authorizedRequestFor($account)->post(BSKY_PDS.'/xrpc/com.atproto.repo.createRecord', ['repo' => BSKY_DID]);

    Http::assertSent(function (Request $request) use ($token) {
        $proof = verifiedClaims($request->header('DPoP')[0]);

        return $request->header('Authorization')[0] === 'DPoP bsky-access-1'
            && $proof['header']['jwk'] === DPoP::publicJwk($token->dpop_key)
            && $proof['claims']['ath'] === DPoP::base64url(hash('sha256', 'bsky-access-1', true));
    });
});

it('takes a server without protected resource metadata as its own authorization server', function () {
    Http::fake([
        BSKY_ISSUER.'/.well-known/oauth-protected-resource' => Http::response('Cannot GET', 404),
        BSKY_PDS.'/.well-known/oauth-protected-resource' => Http::response(['authorization_servers' => [BSKY_ISSUER]]),
    ]);

    expect(Atproto::authorizationServerOf(BSKY_ISSUER))->toBe(BSKY_ISSUER)
        ->and(Atproto::authorizationServerOf(BSKY_PDS))->toBe(BSKY_ISSUER);
});
