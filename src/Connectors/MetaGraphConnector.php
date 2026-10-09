<?php

namespace Pr4w\SocialTokens\Connectors;

use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
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
     * Refresh the Meta user credential: extend the stored user token in place,
     * then read its lifetime from debug_token (withTokenLifetime()).
     * Single-phase: Page tokens are static and are not re-derived here.
     */
    public function refreshCredential(SocialToken $token): RenewalResult
    {
        if (empty($token->access_token)) {
            return RenewalResult::terminalFailure('Missing user token.');
        }

        $result = $this->exchangeUserToken($token->access_token);

        return $result->succeeded() ? $this->withTokenLifetime($result) : $result;
    }

    /**
     * A successful exchange completed with what debug_token says about the new
     * token: whether it never expires, its expiry when the exchange gave none,
     * and when its data access lapses. fb_exchange_token reports none of that:
     * a token that never expires came back with "no expiry", and was renewed
     * every week for nothing. Best effort: the exchange stands if
     * debug_token fails.
     */
    public function withTokenLifetime(RenewalResult $result): RenewalResult
    {
        if (! $result->succeeded() || $result->accessToken === null) {
            return $result;
        }

        $data = $this->debugToken($result->accessToken);

        if ($data instanceof RenewalResult) {
            return $result;
        }

        $lifetime = static::tokenLifetime($data);

        return RenewalResult::success(
            accessToken: $result->accessToken,
            expiresAt: $result->expiresAt ?? $lifetime['expires_at'],
            refreshToken: $result->refreshToken,
            refreshExpiresAt: $result->refreshExpiresAt,
            profile: $result->profile,
            neverExpires: $lifetime['never_expires'],
            dataAccessExpiresAt: $lifetime['data_access_expires_at'] ?? $result->dataAccessExpiresAt,
        );
    }

    /**
     * debug_token's `data` about a user token: its scopes, its expiry and its
     * data access expiry. A failed call (top-level error, 5xx, no `data`) is a
     * failure, never an empty answer: callers keep what they already stored.
     *
     * @return array<string, mixed>|RenewalResult
     */
    public function debugToken(#[\SensitiveParameter] string $userToken): array|RenewalResult
    {
        $appToken = $this->appAccessToken();

        if ($appToken instanceof RenewalResult) {
            return $appToken;
        }

        $response = $this->attempt(fn () => $this->http()->acceptJson()->get($this->graphUrl('debug_token'), [
            'input_token' => $userToken,
            'access_token' => $appToken,
        ]));

        if ($response instanceof RenewalResult) {
            return $response;
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if (! empty($body['error'])) {
            return MetaErrorMapper::map($body['error']);
        }

        $data = $body['data'] ?? null;

        if ($response->failed() || ! is_array($data)) {
            return RenewalResult::unknownFailure('Malformed debug_token response.', ['status' => $response->status()]);
        }

        if (! empty($data['error'])) {
            return MetaErrorMapper::map($data['error']);
        }

        return $data;
    }

    /**
     * A token's lifetime in a debug_token reply. `expires_at` 0 means the
     * token never expires (never_expires, expires_at null); a missing or
     * unreadable value is unknown (both off). `data_access_expires_at` is
     * when Meta stops sharing the user's data with the app, 90 days after the
     * user was last active, whatever the token's own expiry.
     *
     * @param  array<string, mixed>  $data  debugToken()'s result.
     * @return array{never_expires: bool, expires_at: ?CarbonInterface, data_access_expires_at: ?CarbonInterface}
     */
    public static function tokenLifetime(array $data): array
    {
        $expiresAt = $data['expires_at'] ?? null;
        $dataAccess = $data['data_access_expires_at'] ?? null;

        return [
            'never_expires' => is_numeric($expiresAt) && (int) $expiresAt === 0,
            'expires_at' => self::moment($expiresAt),
            'data_access_expires_at' => self::moment($dataAccess),
        ];
    }

    /**
     * A Unix timestamp as a date in the app's timezone, like now(): Eloquent
     * writes a date in its own timezone, so a UTC one would be stored hours
     * off in an app that is not on UTC.
     */
    private static function moment(mixed $timestamp): ?CarbonInterface
    {
        return is_numeric($timestamp) && (int) $timestamp > 0
            ? Carbon::createFromTimestamp((int) $timestamp, date_default_timezone_get())
            : null;
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
