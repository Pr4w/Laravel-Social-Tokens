<?php

namespace Pr4w\SocialTokens\Connectors;

use Carbon\CarbonInterval;
use Illuminate\Http\Client\PendingRequest;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialToken;
use Throwable;

/**
 * X (Twitter) publishing through the X API v2 with OAuth 2.0 (Authorization
 * Code with PKCE). Connect with Socialite's `x` driver (laravel/socialite
 * 5.16+), whose credentials this connector reads from `services.x`.
 *
 * Token facts (docs.x.com, October 2026):
 *  - the access token lives 2 hours (expires_in 7200)
 *  - a refresh token is issued only with the `offline.access` scope; it is
 *    single-use and ROTATES on every refresh, and lives about 6 months
 *  - a confidential client ("Web App") authenticates to the token and revoke
 *    endpoints with HTTP Basic (client_id:client_secret); the secret alone in
 *    the body is rejected
 *  - X keeps one live grant per (app, X user): when the same X account
 *    authorises the app again, the previous tokens die
 *  - a dead refresh token is answered with invalid_request "Value passed for
 *    the token was invalid." rather than invalid_grant
 *
 * OAuth 1.0a is not supported: X is retiring it, and its token secret has no
 * column here.
 */
class XConnector extends OAuth2Connector
{
    protected const TOKEN_URL = 'https://api.x.com/2/oauth2/token';

    protected const REVOKE_URL = 'https://api.x.com/2/oauth2/revoke';

    public function renewalStrategy(): RenewalStrategy
    {
        return RenewalStrategy::RotatingRefreshToken;
    }

    public function leadTime(): CarbonInterval
    {
        // 2h token. Comfortably more than the dispatcher cadence (15 min) plus
        // queue latency, without rotating the single-use token needlessly often.
        return CarbonInterval::minutes(30);
    }

    protected function tokenUrl(): string
    {
        return self::TOKEN_URL;
    }

    protected function tokenRequest(): PendingRequest
    {
        return parent::tokenRequest()->withBasicAuth((string) $this->clientId(), (string) $this->clientSecret());
    }

    /** The client authenticates with Basic: only its id goes in the body. */
    protected function refreshParameters(#[\SensitiveParameter] string $refreshToken): array
    {
        return [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId(),
        ];
    }

    protected function missingRefreshTokenReason(): string
    {
        return 'No refresh token: request the offline.access scope when connecting X.';
    }

    /**
     * X answers a dead refresh token (used, rotated out, expired, revoked, or
     * superseded by a newer authorisation of the same X account) with
     * invalid_request "Value passed for the token was invalid.". Other
     * invalid_request answers ("Missing required parameter [refresh_token].")
     * are requests the code built wrong: app-side, whatever they mention.
     * unauthorized_client covers both a bad Authorization header and a
     * suspended app: app-side too.
     */
    protected function classifyError(string $error, string $description, int $status): ?string
    {
        return match (true) {
            $error === 'invalid_grant' => self::ERROR_TERMINAL,
            $error === 'invalid_request' && (bool) preg_match('/value passed for the token was invalid/i', $description) => self::ERROR_TERMINAL,
            in_array($error, ['invalid_request', 'invalid_client', 'unauthorized_client'], true) => self::ERROR_CLIENT,
            in_array($error, ['rate_limited', 'temporarily_unavailable', 'server_error'], true) => self::ERROR_TRANSIENT,
            default => null,
        };
    }

    /**
     * Revoke the grant at X (best effort): the refresh token when there is one,
     * else the access token. A failure is logged, never thrown.
     */
    public function revoke(SocialToken $token): void
    {
        try {
            [$value, $hint] = ! empty($token->refresh_token)
                ? [$token->refresh_token, 'refresh_token']
                : [$token->access_token, 'access_token'];

            if (empty($value)) {
                return;
            }

            $this->logUnconfirmedRevoke($token, parent::tokenRequest()
                ->withBasicAuth((string) $this->clientId(), (string) $this->clientSecret())
                ->post(self::REVOKE_URL, [
                    'token' => $value,
                    'token_type_hint' => $hint,
                    'client_id' => $this->clientId(),
                ]));
        } catch (Throwable $e) {
            $this->logRevokeFailure($token, $e);
        }
    }
}
