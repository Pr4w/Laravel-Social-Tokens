<?php

namespace Pr4w\SocialTokens\Connectors;

use Carbon\CarbonInterval;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Support\RenewalResult;
use Throwable;

/**
 * TikTok Content Posting API connector.
 *
 * Token facts (verified against TikTok developer docs):
 *  - access_token lives 24h (expires_in 86400)
 *  - refresh_token lives 365 days (refresh_expires_in 31536000) and ROTATES:
 *    every refresh returns a new refresh_token, the old one is dropped
 *  - refresh uses client_key (not client_id) and needs no user consent
 *
 * This is the reference implementation. A new provider is one new class like
 * this one, registered in config under its key.
 */
class TikTokConnector extends AbstractConnector
{
    protected const TOKEN_URL = 'https://open.tiktokapis.com/v2/oauth/token/';
    protected const REVOKE_URL = 'https://open.tiktokapis.com/v2/oauth/revoke/';

    public function key(): string
    {
        return 'tiktok';
    }

    public function publishingScopes(): array
    {
        return ['user.info.basic', 'video.upload', 'video.publish'];
    }

    public function renewalStrategy(): RenewalStrategy
    {
        return RenewalStrategy::RotatingRefreshToken;
    }

    public function leadTime(): CarbonInterval
    {
        // 24h token, renew a couple of hours early.
        return CarbonInterval::hours(2);
    }

    public function renew(SocialAccount $account): RenewalResult
    {
        if (empty($account->refresh_token)) {
            return RenewalResult::terminalFailure('Missing refresh token.');
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->post(self::TOKEN_URL, [
                    'client_key' => $this->clientId(),
                    'client_secret' => $this->clientSecret(),
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $account->refresh_token,
                ]);
        } catch (ConnectionException $e) {
            return RenewalResult::transientFailure('Connection error: '.$e->getMessage());
        } catch (Throwable $e) {
            return RenewalResult::transientFailure('Unexpected error: '.$e->getMessage());
        }

        // Provider 5xx or rate limit: recoverable, retry later.
        if ($response->serverError() || $response->status() === 429) {
            return RenewalResult::transientFailure('Provider returned HTTP '.$response->status());
        }

        $body = $response->json() ?? [];

        if (! empty($body['error'])) {
            return $this->mapError($body);
        }

        $accessToken = $body['access_token'] ?? null;

        if ($accessToken === null) {
            return RenewalResult::unknownFailure('Malformed response, no access_token.', [
                'status' => $response->status(),
                'body' => $body,
            ]);
        }

        return RenewalResult::success(
            accessToken: $accessToken,
            expiresAt: isset($body['expires_in']) ? now()->addSeconds((int) $body['expires_in']) : null,
            // Rotating: persist the NEW refresh token.
            refreshToken: $body['refresh_token'] ?? null,
            refreshExpiresAt: isset($body['refresh_expires_in'])
                ? now()->addSeconds((int) $body['refresh_expires_in'])
                : null,
            profile: array_filter([
                'open_id' => $body['open_id'] ?? null,
                'scope' => $body['scope'] ?? null,
            ]),
        );
    }

    public function revoke(SocialAccount $account): void
    {
        if (empty($account->access_token)) {
            return;
        }

        try {
            Http::asForm()->post(self::REVOKE_URL, [
                'client_key' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'token' => $account->access_token,
            ]);
        } catch (Throwable) {
            // Best effort. The local status is what matters for our flow.
        }
    }

    /**
     * Map a TikTok OAuth error body to a transient or terminal failure.
     */
    protected function mapError(array $body): RenewalResult
    {
        $error = (string) ($body['error'] ?? 'unknown');
        $description = (string) ($body['error_description'] ?? '');

        $terminal = [
            'invalid_grant',          // refresh token revoked or expired
            'invalid_request',        // malformed, will not fix itself
            'access_denied',
            'invalid_client',
        ];

        if (in_array($error, $terminal, true)) {
            return RenewalResult::terminalFailure(trim("{$error}: {$description}"));
        }

        // Unrecognised: flagged as unknown so it gets logged and catalogued.
        // Behaves as transient so a provider hiccup does not burn the account.
        return RenewalResult::unknownFailure(trim("{$error}: {$description}"), [
            'error' => $error,
            'error_description' => $description,
        ]);
    }
}
