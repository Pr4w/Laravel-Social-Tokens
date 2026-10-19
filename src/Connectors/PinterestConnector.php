<?php

namespace Pr4w\SocialTokens\Connectors;

use Carbon\CarbonInterval;
use Illuminate\Http\Client\PendingRequest;
use Pr4w\SocialTokens\Contracts\ChecksCredential;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\RenewalResult;
use Pr4w\SocialTokens\Support\Settings;

/**
 * Pinterest publishing through the Pinterest API v5 (OAuth 2.0). Connect with
 * the package's `pinterest` Socialite driver, which reads its client from
 * `services.pinterest`, as this connector does.
 *
 * Token facts (developers.pinterest.com, October 2026):
 *  - the access token lives 30 days (expires_in 2592000)
 *  - the refresh token is "continuous": it lives 60 days and every refresh
 *    returns a new one (refresh_token_expires_in 5184000), so a credential
 *    renewed within 60 days never expires. Apps created since 2025-09-25 get
 *    no other kind (the 365-day legacy one is gone)
 *  - the client authenticates to the token endpoint with HTTP Basic
 *    (client_id:client_secret); the body carries only the grant
 *  - errors come back as {code, message}, not RFC 6749's error and
 *    error_description; an expired or invalid access token is HTTP 401, code 2
 *  - a member's tokens die when they change their username or password, and
 *    Pinterest asks for a new authorization then
 *  - there is no revocation for a member's tokens (POST /oauth/token/revoke
 *    only takes system users' ones): the member removes the app in their
 *    Pinterest settings
 *  - an app with Trial access publishes in the Sandbox
 *    (api-sandbox.pinterest.com), whose tokens work nowhere else. The
 *    `sandbox` option (services.pinterest.sandbox) points the driver and the
 *    renewals there; accounts connected in the Sandbox reconnect once it is off
 */
class PinterestConnector extends OAuth2Connector implements ChecksCredential
{
    public const API_URL = 'https://api.pinterest.com/v5/';

    public const SANDBOX_API_URL = 'https://api-sandbox.pinterest.com/v5/';

    public function renewalStrategy(): RenewalStrategy
    {
        return RenewalStrategy::RotatingRefreshToken;
    }

    public function leadTime(): CarbonInterval
    {
        // 30-day token: three days ahead leaves room for retries through a
        // Pinterest outage. Each renewal also restarts the refresh token's 60 days.
        return CarbonInterval::days(3);
    }

    /**
     * Whether this app works in the Sandbox (Trial access). Read as every
     * boolean setting (Settings::flag()): PINTEREST_SANDBOX=off reaches the
     * config as the string 'off', which a cast took for true, sending a
     * production app's renewals to the Sandbox.
     */
    public function sandbox(): bool
    {
        return Settings::flag($this->config['sandbox'] ?? config("services.{$this->credentialsKey()}.sandbox", false), false);
    }

    /** The Pinterest API the app's calls go to, ending with a slash: the Sandbox's when sandbox() is on. */
    public function apiUrl(): string
    {
        return $this->sandbox() ? self::SANDBOX_API_URL : self::API_URL;
    }

    protected function tokenUrl(): string
    {
        return $this->apiUrl().'oauth/token';
    }

    protected function tokenRequest(): PendingRequest
    {
        return parent::tokenRequest()->withBasicAuth((string) $this->clientId(), (string) $this->clientSecret());
    }

    /** The client authenticates with Basic: only the grant goes in the body. */
    protected function refreshParameters(#[\SensitiveParameter] string $refreshToken): array
    {
        return [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ];
    }

    protected function refreshExpiresInKey(): ?string
    {
        return 'refresh_token_expires_in';
    }

    /** Pinterest answers {code, message}: read the code as the error. */
    protected function tokenError(array $body, int $status): ?array
    {
        if ($status < 400 || ! isset($body['code'])) {
            return parent::tokenError($body, $status);
        }

        return [
            'error' => is_scalar($body['code']) ? (string) $body['code'] : 'unknown',
            'error_description' => is_scalar($body['message'] ?? null) ? (string) $body['message'] : '',
        ];
    }

    /**
     * Pinterest documents no answer for a dead refresh token, so one is told
     * by its message (it names the refresh token). A 401 is the token
     * endpoint refusing the app's own client (code 2, "Authentication
     * failed."): app-side. Anything else is retried and logged, to be
     * catalogued here once seen.
     */
    protected function classifyError(string $error, string $description, int $status): ?string
    {
        return match (true) {
            $this->isAboutRefreshToken($description) => self::ERROR_TERMINAL,
            $status === 401 => self::ERROR_CLIENT,
            default => null,
        };
    }

    /**
     * Whether Pinterest still accepts the access token: GET /user_account.
     * Its access tokens live 30 days, so a 401 before that means the member
     * changed their username or password (or the token leaked and Pinterest
     * revoked it), and only a new authorization brings it back. After the 30
     * days, renewal refreshes it.
     */
    public function checkCredential(SocialToken $token): RenewalResult
    {
        if (empty($token->access_token)) {
            return RenewalResult::terminalFailure('Missing access token.', ['definitive' => true]);
        }

        $accessToken = $token->access_token;

        $response = $this->attempt(fn () => $this->http()->acceptJson()->withToken($accessToken)->get($this->apiUrl().'user_account'));

        if ($response instanceof RenewalResult) {
            return $response;
        }

        if ($response->successful()) {
            return RenewalResult::success(accessToken: $accessToken);
        }

        $code = $response->json('code');
        $message = $response->json('message');
        $context = [
            'status' => $response->status(),
            'code' => is_scalar($code) ? $code : null,
            'message' => is_string($message) ? $message : null,
        ];

        if ($response->status() !== 401) {
            return RenewalResult::unknownFailure('Unexpected Pinterest answer to GET /user_account (HTTP '.$response->status().').', $context);
        }

        if ($token->expires_at !== null && $token->expires_at->isPast()) {
            return RenewalResult::transientFailure('Pinterest access token expired; renewal refreshes it.', $context);
        }

        return RenewalResult::terminalFailure('Pinterest rejected the access token'.(is_string($message) ? " ({$message})" : '').'.', $context);
    }
}
