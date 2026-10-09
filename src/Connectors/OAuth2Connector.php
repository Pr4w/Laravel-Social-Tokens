<?php

namespace Pr4w\SocialTokens\Connectors;

use Illuminate\Http\Client\PendingRequest;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\RenewalResult;

/**
 * Base for providers that renew through an OAuth 2.0 refresh_token grant
 * (TikTok, Google, LinkedIn, X). It owns the request, the response parsing and
 * the single error-classification flow; a provider declares its token endpoint,
 * its request parameters and how its error codes read (classifyError()).
 *
 * Extend this, not a sibling connector, to add an OAuth 2.0 provider.
 */
abstract class OAuth2Connector extends AbstractConnector
{
    /** The member's grant is gone: flag for reconnection. */
    protected const ERROR_TERMINAL = 'terminal';

    /** The app's own OAuth client is rejected: retried and alerted, never flagged. */
    protected const ERROR_CLIENT = 'client';

    /** A known temporary condition: retried. */
    protected const ERROR_TRANSIENT = 'transient';

    abstract protected function tokenUrl(): string;

    /**
     * The form fields of the refresh request.
     *
     * @return array<string, mixed>
     */
    protected function refreshParameters(#[\SensitiveParameter] string $refreshToken): array
    {
        return [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
        ];
    }

    /** The base request for the token endpoint (add client authentication here). */
    protected function tokenRequest(): PendingRequest
    {
        return $this->http()->asForm()->acceptJson();
    }

    protected function missingRefreshTokenReason(): string
    {
        return 'Missing refresh token.';
    }

    /** The response field holding the refresh token's lifetime in seconds, if the provider sends one. */
    protected function refreshExpiresInKey(): ?string
    {
        return null;
    }

    /**
     * Provider details kept on the result (the granted `scope` updates the
     * credential's scopes).
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function profileFrom(array $body): array
    {
        return array_filter(['scope' => $body['scope'] ?? null]);
    }

    /**
     * Classify a token-endpoint error: ERROR_TERMINAL, ERROR_CLIENT,
     * ERROR_TRANSIENT, or null when unrecognised (retried, and logged so it can
     * be catalogued). The default follows RFC 6749: invalid_grant (and an
     * invalid_request about the refresh token) means the grant is gone; the
     * client errors describe the app's own configuration.
     */
    protected function classifyError(string $error, string $description, int $status): ?string
    {
        return match (true) {
            $error === 'invalid_grant' => self::ERROR_TERMINAL,
            $error === 'invalid_request' && $this->isAboutRefreshToken($description) => self::ERROR_TERMINAL,
            in_array($error, ['invalid_client', 'unauthorized_client', 'invalid_request'], true) => self::ERROR_CLIENT,
            in_array($error, ['temporarily_unavailable', 'server_error'], true) => self::ERROR_TRANSIENT,
            default => null,
        };
    }

    public function refreshCredential(SocialToken $token): RenewalResult
    {
        $refreshToken = $token->refresh_token;

        if (empty($refreshToken)) {
            return RenewalResult::terminalFailure($this->missingRefreshTokenReason());
        }

        $response = $this->attempt(fn () => $this->tokenRequest()->post($this->tokenUrl(), $this->refreshParameters($refreshToken)));

        if ($response instanceof RenewalResult) {
            return $response;
        }

        $body = $response->json();
        $body = is_array($body) ? $body : []; // a scalar JSON body is not an error object

        if (! empty($body['error'])) {
            return $this->mapError($body, $response->status());
        }

        $accessToken = $body['access_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            return RenewalResult::unknownFailure('Malformed response, no access_token.', [
                'status' => $response->status(),
                'keys' => array_keys($body), // never the body: it may carry tokens
            ]);
        }

        $newRefreshToken = $body['refresh_token'] ?? null;
        $refreshExpiresIn = ($key = $this->refreshExpiresInKey()) !== null ? ($body[$key] ?? null) : null;

        return RenewalResult::success(
            accessToken: $accessToken,
            expiresAt: is_numeric($body['expires_in'] ?? null) ? now()->addSeconds((int) $body['expires_in']) : null,
            // A rotating provider returns a new refresh token, which must be
            // stored; null keeps the one already stored (stable providers).
            refreshToken: is_string($newRefreshToken) && $newRefreshToken !== '' ? $newRefreshToken : null,
            refreshExpiresAt: is_numeric($refreshExpiresIn) ? now()->addSeconds((int) $refreshExpiresIn) : null,
            profile: $this->profileFrom($body),
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function mapError(array $body, int $status): RenewalResult
    {
        // RFC 6749 sends `error` as a string; tolerate an object ({code, message}).
        $raw = $body['error'];

        if (is_array($raw)) {
            $error = self::text($raw['code'] ?? $raw['type'] ?? $raw['error'] ?? null) ?: 'unknown';
            $description = self::text($raw['message'] ?? $raw['description'] ?? $body['error_description'] ?? null);
        } else {
            $error = self::text($raw) ?: 'unknown';
            $description = self::text($body['error_description'] ?? null);
        }

        $reason = trim("{$error}: {$description}");
        $context = ['error' => $error, 'error_description' => $description, 'status' => $status];

        return match ($this->classifyError($error, $description, $status)) {
            self::ERROR_TERMINAL => RenewalResult::terminalFailure($reason, $context),
            self::ERROR_CLIENT => RenewalResult::clientFailure($reason, $context),
            self::ERROR_TRANSIENT => RenewalResult::transientFailure($reason, $context),
            default => RenewalResult::unknownFailure($reason, $context),
        };
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
