<?php

namespace Pr4w\SocialTokens\Connectors;

use Carbon\CarbonInterval;
use Closure;
use Pr4w\SocialTokens\Contracts\FetchesProfile;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\AccountProfile;
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
 * The refresh flow itself lives in OAuth2Connector; this class only declares
 * TikTok's endpoint, parameter names and error codes.
 */
class TikTokConnector extends OAuth2Connector implements FetchesProfile
{
    protected const TOKEN_URL = 'https://open.tiktokapis.com/v2/oauth/token/';

    protected const REVOKE_URL = 'https://open.tiktokapis.com/v2/oauth/revoke/';

    protected const USER_INFO_URL = 'https://open.tiktokapis.com/v2/user/info/';

    public function renewalStrategy(): RenewalStrategy
    {
        return RenewalStrategy::RotatingRefreshToken;
    }

    /**
     * The display name and avatar (user.info.basic). TikTok answers its
     * errors in error.code, "ok" on success.
     */
    public function fetchProfile(SocialAccount $account, Closure $accessToken): AccountProfile|RenewalResult|null
    {
        if ($this->grantsNoneOf($account, ['user.info.basic'])) {
            return null;
        }

        $token = $accessToken();
        $body = $this->profileBody($this->attempt(fn () => $this->http()->withToken($token)->acceptJson()
            ->get(self::USER_INFO_URL, ['fields' => 'open_id,display_name,avatar_url'])), 'the TikTok profile');

        if (! is_array($body)) {
            return $body;
        }

        $code = data_get($body, 'error.code');

        if ($code === 'scope_not_authorized') {
            return null;
        }

        if ($code !== null && $code !== 'ok') {
            return RenewalResult::unknownFailure('Could not read the TikTok profile: '.(is_scalar($code) ? $code : 'unknown error').'.', ['code' => is_scalar($code) ? $code : null]);
        }

        return AccountProfile::from(data_get($body, 'data.user.display_name'), null, data_get($body, 'data.user.avatar_url'));
    }

    public function leadTime(): CarbonInterval
    {
        // 24h token, renew a couple of hours early.
        return CarbonInterval::hours(2);
    }

    protected function tokenUrl(): string
    {
        return self::TOKEN_URL;
    }

    /** TikTok names the client id `client_key`. */
    protected function refreshParameters(#[\SensitiveParameter] string $refreshToken): array
    {
        return [
            'client_key' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ];
    }

    protected function refreshExpiresInKey(): ?string
    {
        return 'refresh_expires_in';
    }

    protected function profileFrom(array $body): array
    {
        return array_filter([
            'open_id' => $body['open_id'] ?? null,
            'scope' => $body['scope'] ?? null,
        ]);
    }

    /** access_denied: the user withdrew the app's access. */
    protected function classifyError(string $error, string $description, int $status): ?string
    {
        return $error === 'access_denied' ? self::ERROR_TERMINAL : parent::classifyError($error, $description, $status);
    }

    /**
     * Best effort, never throws: the credential is already revoked locally.
     * An answer that does not confirm it (TikTok may send its error with a
     * 200) is logged.
     */
    public function revoke(SocialToken $token): void
    {
        try {
            $accessToken = $token->access_token;

            if (empty($accessToken)) {
                return;
            }

            $this->logUnconfirmedRevoke($token, $this->http()->asForm()->post(self::REVOKE_URL, [
                'client_key' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'token' => $accessToken,
            ]));
        } catch (Throwable $e) {
            $this->logRevokeFailure($token, $e);
        }
    }
}
