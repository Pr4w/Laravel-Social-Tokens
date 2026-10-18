<?php

namespace Pr4w\SocialTokens\Connectors;

use Carbon\CarbonInterval;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\User as SocialiteUser;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Socialite\BlueskyUser;
use Pr4w\SocialTokens\Support\DPoP;
use Pr4w\SocialTokens\Support\RenewalResult;
use RuntimeException;
use Throwable;

/**
 * Bluesky (AT Protocol OAuth). Connect with the package's `bluesky` Socialite
 * driver (Socialite\BlueskyProvider), configured in `services.bluesky`.
 *
 * What sets it apart from the other OAuth 2.0 providers:
 *  - no client registration: the client_id is the https URL of the app's
 *    client metadata document (clientMetadata()), which the authorization
 *    server fetches. Without one (local development), the loopback client
 *    `http://localhost` is used, a public client, with short sessions.
 *  - a confidential client authenticates with private_key_jwt: an assertion
 *    signed with the app's ES256 key (services.bluesky.private_key), whose
 *    public half the app serves as its JWKS (jwks()).
 *  - every token is DPoP-bound to a key pair generated for the connection
 *    and stored with the credential (dpop_key): renewals and API calls are
 *    signed with it (Support\DPoP, SocialTokens::authorizedRequestFor()).
 *  - the authorization server is per account (the PDS names it): its token
 *    and revocation endpoints are stored on the credential (metadata).
 *  - access tokens live minutes; refresh tokens are single-use and rotate,
 *    and live months for a confidential client (two weeks for a public one).
 *    Access tokens are renewed on demand (validAccessTokenFor()); the
 *    dispatcher only renews daily, to keep the refresh token alive.
 *
 * @see https://atproto.com/specs/oauth
 */
class BlueskyConnector extends OAuth2Connector
{
    public const DEFAULT_SCOPES = ['atproto', 'transition:generic'];

    /** The credential being renewed: its DPoP key and endpoints sign the request. */
    private ?SocialToken $renewing = null;

    public function renewalStrategy(): RenewalStrategy
    {
        return RenewalStrategy::RotatingRefreshToken;
    }

    public function leadTime(): CarbonInterval
    {
        return CarbonInterval::minutes(2);
    }

    public function keepAlive(): ?CarbonInterval
    {
        return CarbonInterval::day();
    }

    // Client ----------------------------------------------------------------

    /** The URL of the client metadata document, or the loopback client id. */
    public function clientId(): string
    {
        $id = parent::clientId();

        if (is_string($id) && str_starts_with($id, 'https://')) {
            return $id;
        }

        // The loopback client: its redirect URI and scope ride in the id
        return 'http://localhost?'.http_build_query(array_filter([
            'redirect_uri' => $this->redirectUri(),
            'scope' => $this->scope(),
        ]), '', '&', PHP_QUERY_RFC3986);
    }

    public function isConfidential(): bool
    {
        return str_starts_with($this->clientId(), 'https://') && filled($this->privateKey());
    }

    public function redirectUri(): ?string
    {
        return $this->config['redirect'] ?? config("services.{$this->credentialsKey()}.redirect");
    }

    public function scope(): string
    {
        $scopes = $this->config['scopes'] ?? config("services.{$this->credentialsKey()}.scopes") ?? self::DEFAULT_SCOPES;

        return implode(' ', (array) $scopes);
    }

    /**
     * The client metadata document to serve at the client_id URL.
     *
     * @return array<string, mixed>
     */
    public function clientMetadata(): array
    {
        $services = (array) config("services.{$this->credentialsKey()}", []);

        return array_filter([
            'client_id' => $this->clientId(),
            'application_type' => 'web',
            'client_name' => $services['client_name'] ?? config('app.name'),
            'client_uri' => $services['client_uri'] ?? config('app.url'),
            'logo_uri' => $services['logo_uri'] ?? null,
            'tos_uri' => $services['tos_uri'] ?? null,
            'policy_uri' => $services['policy_uri'] ?? null,
            'redirect_uris' => [$this->redirectUri()],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'scope' => $this->scope(),
            'dpop_bound_access_tokens' => true,
            'token_endpoint_auth_method' => 'private_key_jwt',
            'token_endpoint_auth_signing_alg' => 'ES256',
            'jwks_uri' => $services['jwks_uri'] ?? null,
            'jwks' => isset($services['jwks_uri']) ? null : $this->jwks(),
        ], fn ($value) => $value !== null);
    }

    /**
     * The public half of the client key, to serve at jwks_uri.
     *
     * @return array{keys: array<int, array<string, string>>}
     */
    public function jwks(): array
    {
        $key = $this->privateKey();

        return ['keys' => $key ? [DPoP::publicJwk($key) + ['kid' => $this->keyId(), 'use' => 'sig', 'alg' => 'ES256']] : []];
    }

    /**
     * The client authentication fields for a request to $issuer.
     *
     * @return array<string, string>
     */
    public function clientAuthentication(string $issuer): array
    {
        if (! $this->isConfidential()) {
            return ['client_id' => $this->clientId()];
        }

        return [
            'client_id' => $this->clientId(),
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => DPoP::sign((string) $this->privateKey(), ['kid' => $this->keyId()], [
                'iss' => $this->clientId(),
                'sub' => $this->clientId(),
                'aud' => $issuer,
                'jti' => (string) Str::uuid(),
                'iat' => time(),
                'exp' => time() + 60,
            ]),
        ];
    }

    // Connect ---------------------------------------------------------------

    /**
     * Push the authorization request (PAR, required), signed with the
     * connection's DPoP key. Returns its request_uri.
     *
     * @param  array<string, mixed>  $server  the authorization server metadata
     * @param  array<string, string|null>  $parameters  state, code_challenge, login_hint…
     */
    public function pushAuthorizationRequest(array $server, #[\SensitiveParameter] string $dpopKey, array $parameters): string
    {
        $response = $this->dpopRequest($dpopKey)->post($server['pushed_authorization_request_endpoint'], array_filter([
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'scope' => $this->scope(),
            'code_challenge_method' => 'S256',
            ...$parameters,
            ...$this->clientAuthentication($server['issuer']),
        ], fn ($value) => $value !== null && $value !== ''));

        $requestUri = $response->json('request_uri');

        if (! $response->successful() || ! is_string($requestUri)) {
            throw new RuntimeException('Bluesky refused the authorization request: '.$this->errorOf($response));
        }

        return $requestUri;
    }

    /**
     * Exchange the authorization code. Returns the token response (access and
     * refresh tokens, expires_in, scope, and sub: the account's DID).
     *
     * @param  array<string, mixed>  $server
     * @return array<string, mixed>
     */
    public function exchangeCode(array $server, #[\SensitiveParameter] string $dpopKey, #[\SensitiveParameter] string $code, #[\SensitiveParameter] string $codeVerifier): array
    {
        $response = $this->dpopRequest($dpopKey)->post($server['token_endpoint'], [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $codeVerifier,
            'redirect_uri' => $this->redirectUri(),
            ...$this->clientAuthentication($server['issuer']),
        ]);

        $body = $response->json();

        if (! $response->successful() || ! is_string($body['access_token'] ?? null) || ! is_string($body['sub'] ?? null)) {
            throw new RuntimeException('Bluesky refused the authorization code: '.$this->errorOf($response));
        }

        if (strtolower((string) ($body['token_type'] ?? '')) !== 'dpop') {
            throw new RuntimeException('Bluesky issued a token that is not DPoP-bound.');
        }

        return $body;
    }

    /**
     * The DPoP key and server endpoints a connection's credential keeps.
     *
     * @return array<model-property<SocialToken>, mixed>
     */
    public function credentialAttributesFrom(#[\SensitiveParameter] SocialiteUser $user): array
    {
        if (! $user instanceof BlueskyUser) {
            throw new RuntimeException('Connect Bluesky with the package\'s "bluesky" Socialite driver.');
        }

        return ['dpop_key' => $user->dpopKey, 'metadata' => $user->credentialMetadata];
    }

    // Renew -----------------------------------------------------------------

    public function refreshCredential(SocialToken $token): RenewalResult
    {
        if (blank($token->dpop_key) || blank($token->metadata['token_endpoint'] ?? null)) {
            return RenewalResult::terminalFailure('No DPoP key or token endpoint stored: reconnect Bluesky.');
        }

        $this->renewing = $token;

        try {
            return parent::refreshCredential($token);
        } finally {
            $this->renewing = null;
        }
    }

    protected function tokenUrl(): string
    {
        return (string) ($this->renewing?->metadata['token_endpoint'] ?? '');
    }

    protected function tokenRequest(): PendingRequest
    {
        return $this->dpopRequest((string) $this->renewing?->dpop_key);
    }

    protected function refreshParameters(#[\SensitiveParameter] string $refreshToken): array
    {
        return [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            ...$this->clientAuthentication((string) ($this->renewing?->metadata['issuer'] ?? '')),
        ];
    }

    protected function missingRefreshTokenReason(): string
    {
        return 'No refresh token: reconnect Bluesky.';
    }

    /**
     * invalid_grant: the refresh token was used, expired or revoked (the user
     * removed the app in Bluesky's settings). invalid_dpop_proof and the client
     * errors are the app's own doing.
     */
    protected function classifyError(string $error, string $description, int $status): ?string
    {
        return match (true) {
            $error === 'invalid_grant' => self::ERROR_TERMINAL,
            in_array($error, ['invalid_client', 'unauthorized_client', 'invalid_dpop_proof', 'invalid_request'], true) => self::ERROR_CLIENT,
            in_array($error, ['use_dpop_nonce', 'temporarily_unavailable', 'server_error'], true) => self::ERROR_TRANSIENT,
            default => null,
        };
    }

    /** Revoke the refresh token at the account's authorization server (best effort). */
    public function revoke(SocialToken $token): void
    {
        try {
            $endpoint = $token->metadata['revocation_endpoint'] ?? null;
            $value = $token->refresh_token ?: $token->access_token;

            if (! is_string($endpoint) || blank($value) || blank($token->dpop_key)) {
                return;
            }

            $this->logUnconfirmedRevoke($token, $this->dpopRequest($token->dpop_key)->post($endpoint, [
                'token' => $value,
                ...$this->clientAuthentication((string) ($token->metadata['issuer'] ?? '')),
            ]));
        } catch (Throwable $e) {
            $this->logRevokeFailure($token, $e);
        }
    }

    // Internals -------------------------------------------------------------

    protected function dpopRequest(#[\SensitiveParameter] string $dpopKey): PendingRequest
    {
        return $this->http()->asForm()->acceptJson()->withMiddleware(DPoP::middleware($dpopKey));
    }

    protected function privateKey(): ?string
    {
        $key = $this->config['private_key'] ?? config("services.{$this->credentialsKey()}.private_key");

        // A PEM in .env usually has its newlines escaped
        return is_string($key) && $key !== '' ? str_replace('\n', "\n", $key) : null;
    }

    protected function keyId(): string
    {
        return (string) ($this->config['key_id'] ?? config("services.{$this->credentialsKey()}.key_id") ?? 'k1');
    }

    private function errorOf(Response $response): string
    {
        return trim(($response->json('error') ?? 'HTTP '.$response->status()).': '.($response->json('error_description') ?? ''), ': ');
    }
}
