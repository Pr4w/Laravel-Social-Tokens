<?php

namespace Pr4w\SocialTokens;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Pr4w\SocialTokens\Contracts\ProviderConnector;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Exceptions\NeedsReconnectException;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Pr4w\SocialTokens\Support\RenewalResult;

/**
 * The single door the publishing layer uses. It knows nothing about refresh
 * tokens or per-provider strategies: it asks for a valid access token and
 * either gets one or is told the account must be reconnected.
 *
 * Renewal happens on the credential (SocialToken), once, and every account that
 * shares it sees the fresh token.
 */
class SocialTokens
{
    public function __construct(protected ConnectorRegistry $registry) {}

    public function connector(string $provider): ProviderConnector
    {
        return $this->registry->for($provider);
    }

    /**
     * Refresh a credential under a per-credential lock, with a double-check so a
     * concurrent renewal (scheduled job vs synchronous call) never refreshes
     * twice. The second arrival re-reads the freshly renewed token instead of
     * calling the provider again, which matters for rotating-refresh-token
     * providers like TikTok where a double refresh invalidates the token.
     *
     * Applies the result on success (single source of truth). Failures are
     * returned for the caller to handle, since the job and the synchronous
     * path react differently to transient failures.
     */
    public function renewCredential(SocialToken $token): RenewalResult
    {
        $connector = $this->registry->for($token->provider);

        try {
            return Cache::lock($this->lockKey($token), 30)->block(10, function () use ($token, $connector) {
                $token->refresh();

                // Another process may have renewed while we waited for the lock.
                if (! $token->isAccessTokenExpired() && $token->access_token !== null) {
                    return RenewalResult::success(
                        accessToken: $token->access_token,
                        expiresAt: $token->expires_at,
                    );
                }

                $result = $connector->refreshCredential($token);

                if ($result->unknown && config('social-tokens.log_unknown_errors', true)) {
                    Log::error('[social-tokens] Uncatalogued renewal error', [
                        'provider' => $token->provider,
                        'token_id' => $token->getKey(),
                        'reason' => $result->reason,
                        'context' => $result->context,
                    ]);
                }

                if ($result->succeeded()) {
                    $token->applyRenewal($result, $connector);
                }

                return $result;
            });
        } catch (LockTimeoutException) {
            // Someone else is renewing and did not finish in time. Transient:
            // the caller can retry, and the in-flight renewal will land.
            return RenewalResult::transientFailure('Could not acquire renewal lock.');
        }
    }

    /**
     * Return a valid access token for the account, renewing its credential
     * synchronously if needed. This is a belt-and-suspenders layer on top of the
     * scheduled job: even if a renewal was missed, a posting attempt still gets a
     * fresh token. A static credential (renew_at null — e.g. a Facebook page
     * token) is returned as-is.
     *
     * @throws NeedsReconnectException
     */
    public function validAccessTokenFor(SocialAccount $account): string
    {
        if (! $account->status->isUsable()) {
            throw NeedsReconnectException::for($account);
        }

        $token = $account->credential;

        if ($token === null || ! $token->status->isUsable()) {
            throw NeedsReconnectException::for($account);
        }

        if (! $token->isAccessTokenExpired() && $token->access_token !== null) {
            return $token->access_token;
        }

        $connector = $this->registry->for($token->provider);

        if (! $connector->renewalStrategy()->canRenewUnattended() || $token->isRefreshTokenExpired()) {
            $token->markNeedsReconnect('Token expired and cannot be renewed unattended.');

            throw NeedsReconnectException::for($account);
        }

        $result = $this->renewCredential($token);

        if ($result->succeeded()) {
            $token->refresh();

            if ($token->access_token !== null) {
                return $token->access_token;
            }
        }

        // Terminal failure: the connection is broken, flag the credential (which
        // fans out to every account it backs). Transient: leave it usable so
        // background retries continue, but we still cannot post on this attempt.
        if ($result->outcome === RenewalOutcome::Terminal) {
            $token->markNeedsReconnect($result->reason);
        }

        throw NeedsReconnectException::for($account, $result->reason);
    }

    protected function lockKey(SocialToken $token): string
    {
        return "social-tokens:renew:{$token->getKey()}";
    }
}
