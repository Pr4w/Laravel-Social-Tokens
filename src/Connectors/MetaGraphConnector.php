<?php

namespace Pr4w\SocialTokens\Connectors;

use Carbon\CarbonInterval;
use Illuminate\Http\Client\Response;
use Pr4w\SocialTokens\Connectors\Concerns\SignsGraphRequests;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\RenewalResult;

/**
 * Base for the connectors that run on Facebook Login tokens and
 * graph.facebook.com: Facebook Pages, and Instagram through the Page it is
 * linked to. Both keep the same credential alive, the long-lived Meta user
 * token (~60 days, no refresh token), extended in place with the
 * fb_exchange_token grant. That call, the Graph base URL and its version
 * default live here once, so the two connectors cannot drift apart.
 *
 * Graph calls made with a user or Page token carry appsecret_proof (see
 * SignsGraphRequests), so the Meta app can require it.
 */
abstract class MetaGraphConnector extends AbstractConnector
{
    use SignsGraphRequests;

    /** The Graph API version used when the connector's block sets none. */
    public const DEFAULT_GRAPH_VERSION = 'v23.0';

    public function renewalStrategy(): RenewalStrategy
    {
        return RenewalStrategy::ExtendLongLived;
    }

    public function leadTime(): CarbonInterval
    {
        // A 60 day token, extended roughly a week early.
        return CarbonInterval::days(7);
    }

    /** The Graph API version of this connector's block (`graph_version`), or the default. */
    public function graphVersion(): string
    {
        $version = $this->config['graph_version'] ?? null;

        return is_string($version) && $version !== '' ? $version : self::DEFAULT_GRAPH_VERSION;
    }

    /** A versioned graph.facebook.com URL. */
    protected function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/'.$this->graphVersion().'/'.ltrim($path, '/');
    }

    /**
     * Refresh the Meta user credential: extend the stored user token in place.
     * Single-phase: Page tokens are static and are not re-derived here.
     */
    public function refreshCredential(SocialToken $token): RenewalResult
    {
        if (empty($token->access_token)) {
            return RenewalResult::terminalFailure('Missing user token.');
        }

        return $this->exchangeUserToken($token->access_token);
    }

    /**
     * Exchange a user token for a fresh long-lived one via the fb_exchange_token
     * grant. Safe to call on a token that is already long lived, but whether
     * Meta then pushes the expiry further is not documented: renewals log the
     * expires_in they get, and a non-extending one is handled by applyRenewal().
     * Shared by renewal and the connect-time actions.
     */
    public function exchangeUserToken(#[\SensitiveParameter] string $userToken): RenewalResult
    {
        $response = $this->attempt(fn () => $this->http()->acceptJson()->get($this->graphUrl('oauth/access_token'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'fb_exchange_token' => $userToken,
        ]));

        if ($response instanceof RenewalResult) {
            return $response;
        }

        $body = $response->json();
        $body = is_array($body) ? $body : []; // a scalar JSON body is not an error object

        if (! empty($body['error'])) {
            return MetaErrorMapper::map($body['error']);
        }

        $token = $body['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            return RenewalResult::unknownFailure('Malformed token extension response.', [
                'status' => $response->status(),
                'keys' => array_keys($body), // never the body: it may carry tokens
            ]);
        }

        return RenewalResult::success(
            accessToken: $token,
            expiresAt: is_numeric($body['expires_in'] ?? null) ? now()->addSeconds((int) $body['expires_in']) : null,
            // No refresh token on this path: the access token is the long lived credential.
            refreshToken: null,
        );
    }

    /**
     * App access token for calling debug_token. Meta accepts the literal string
     * "{app-id}|{app-secret}" as the app token, so there is no endpoint to call
     * and nothing to cache.
     */
    protected function appAccessToken(): string|RenewalResult
    {
        $clientId = $this->clientId();
        $clientSecret = $this->clientSecret();

        if ($clientId === null || $clientSecret === null) {
            return RenewalResult::terminalFailure('Missing Facebook client credentials for the app access token.');
        }

        return "{$clientId}|{$clientSecret}";
    }

    /**
     * GET /me?fields=id with the given token, transport outcomes normalised.
     */
    protected function fetchMe(#[\SensitiveParameter] string $token): Response|RenewalResult
    {
        return $this->attempt(fn () => $this->http()->withToken($token)
            ->acceptJson()
            ->get($this->graphUrl('me'), ['fields' => 'id'] + $this->appSecretProofQuery($token)));
    }
}
