<?php

namespace Pr4w\SocialTokens\Socialite;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use LogicException;
use Pr4w\SocialTokens\Connectors\BlueskyConnector;
use Pr4w\SocialTokens\Support\Atproto;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Pr4w\SocialTokens\Support\DPoP;
use RuntimeException;

/**
 * The `bluesky` Socialite driver: AT Protocol OAuth (PAR, PKCE, DPoP,
 * private_key_jwt), on top of the BlueskyConnector, which holds the client.
 *
 *   Socialite::driver('bluesky')->redirect();                           // the user picks the account at bsky.social
 *   Socialite::driver('bluesky')->with(['login_hint' => $handle])->redirect(); // any PDS
 *   $user = Socialite::driver('bluesky')->user();                       // a BlueskyUser
 *
 * What the callback needs (code verifier, DPoP key, authorization server) is
 * kept in the cache under the state, encrypted, for 10 minutes. The state is
 * also checked against the session, which ties the callback to the browser
 * that started it: without that, a callback URL someone else started would
 * connect their account to whoever opens it. stateless() is for the loopback
 * client of local development only, whose callback comes back on another host
 * (127.0.0.1); a confidential client refuses it.
 */
class BlueskyProvider extends AbstractProvider
{
    protected $scopeSeparator = ' ';

    public function redirect()
    {
        $connector = $this->connector();
        $hint = $this->parameters['login_hint'] ?? null;

        // A handle: its own server. None: the entryway's (bsky.social)
        if (is_string($hint) && $hint !== '') {
            $did = Atproto::resolveHandle($hint);
            $issuer = Atproto::authorizationServerOf(Atproto::pdsOf(Atproto::didDocument($did)));
        } else {
            $did = null;
            $issuer = Atproto::authorizationServerOf(Atproto::entryway());
        }

        $server = Atproto::serverMetadata($issuer);
        $state = Str::random(40);
        $verifier = Str::random(64);
        $dpopKey = DPoP::generateKey();

        $requestUri = $connector->pushAuthorizationRequest($server, $dpopKey, [
            'state' => $state,
            'code_challenge' => DPoP::base64url(hash('sha256', $verifier, true)),
            'login_hint' => $hint ?: null,
        ]);

        Cache::put(self::pendingKey($state), Crypt::encrypt([
            'issuer' => $issuer,
            'did' => $did,
            'verifier' => $verifier,
            'dpop_key' => $dpopKey,
        ]), now()->addMinutes(10));

        if (! $this->isStateless()) {
            $this->request->session()->put('state', $state);
        }

        return new RedirectResponse($server['authorization_endpoint'].'?'.http_build_query([
            'client_id' => $connector->clientId(),
            'request_uri' => $requestUri,
        ], '', '&', PHP_QUERY_RFC3986));
    }

    public function user()
    {
        if ($this->user) {
            return $this->user;
        }

        if ($this->isStateless() && $this->connector()->isConfidential()) {
            throw new LogicException(
                'Bluesky callbacks must keep the session: stateless() is for the loopback client of local development. '
                .'Without the session, the callback is not tied to the browser that started it.'
            );
        }

        $state = (string) $this->request->input('state');
        $pending = $state !== '' ? Cache::pull(self::pendingKey($state)) : null;

        if ($pending === null || $this->hasInvalidState()) {
            throw new InvalidStateException;
        }

        $pending = Crypt::decrypt($pending);

        // The authorization server that answered must be the one we sent the user to
        if (rtrim((string) $this->request->input('iss'), '/') !== $pending['issuer']) {
            throw new RuntimeException('The Bluesky authorization came back from another server.');
        }

        $connector = $this->connector();
        $server = Atproto::serverMetadata($pending['issuer']);
        $body = $connector->exchangeCode($server, $pending['dpop_key'], (string) $this->getCode(), $pending['verifier']);
        $did = $body['sub'];

        // The account must be the one asked for, and its PDS must trust this
        // authorization server: otherwise any server could claim any DID.
        if ($pending['did'] !== null && $pending['did'] !== $did) {
            throw new RuntimeException('Bluesky signed in another account than the one asked for.');
        }

        $document = Atproto::didDocument($did);
        $pds = Atproto::pdsOf($document);

        if (Atproto::authorizationServerOf($pds) !== $pending['issuer']) {
            throw new RuntimeException('That Bluesky account is not served by the server that signed it in.');
        }

        $profile = Atproto::profile($did);
        $handle = $profile['handle'] ?? Atproto::handleOf($document);

        $user = (new BlueskyUser)->setRaw($profile + ['did' => $did, 'pds' => $pds])->map([
            'id' => $did,
            'nickname' => $handle,
            'name' => ($profile['displayName'] ?? '') ?: $handle,
            'email' => null,
            'avatar' => $profile['avatar'] ?? null,
        ]);

        $user->dpopKey = $pending['dpop_key'];
        $user->credentialMetadata = [
            'issuer' => $pending['issuer'],
            'token_endpoint' => $server['token_endpoint'],
            'revocation_endpoint' => $server['revocation_endpoint'] ?? null,
            'pds' => $pds,
        ];

        $this->user = $user->setToken($body['access_token'])
            ->setRefreshToken($body['refresh_token'] ?? null)
            ->setExpiresIn($body['expires_in'] ?? null)
            ->setApprovedScopes(array_values(array_filter(explode(' ', (string) ($body['scope'] ?? '')))));

        return $this->user;
    }

    /** Sign in through this handle's server (a handle, or a DID). */
    public function hint(?string $handle): static
    {
        return $this->with(array_filter(['login_hint' => $handle]));
    }

    protected function connector(): BlueskyConnector
    {
        $connector = app(ConnectorRegistry::class)->for('bluesky');

        if (! $connector instanceof BlueskyConnector) {
            throw new LogicException('The "bluesky" connector must be a BlueskyConnector.');
        }

        return $connector;
    }

    private static function pendingKey(string $state): string
    {
        return 'social-tokens:bluesky-oauth:'.hash('sha256', $state);
    }

    // The OAuth 2 steps above are AT Protocol's own: these are never used.

    protected function getAuthUrl($state)
    {
        throw new LogicException('Bluesky authorization goes through redirect().');
    }

    protected function getTokenUrl()
    {
        throw new LogicException('Bluesky token requests go through the BlueskyConnector.');
    }

    /** Never with a token: AT Protocol tokens are DPoP-bound, and the profile is public (Atproto::profile($did)). */
    protected function getUserByToken($token)
    {
        throw new LogicException('Bluesky users come from user(); read a profile with Atproto::profile($did).');
    }

    /** @param  array<string, mixed>  $user */
    protected function mapUserToObject(array $user)
    {
        return (new BlueskyUser)->setRaw($user);
    }
}
