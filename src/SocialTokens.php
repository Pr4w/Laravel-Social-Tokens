<?php

namespace Pr4w\SocialTokens;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Pr4w\SocialTokens\Contracts\ProviderConnector;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Exceptions\NeedsReconnectException;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Pr4w\SocialTokens\Support\RenewalResult;

/**
 * The single door the publishing layer uses. It knows nothing about refresh
 * tokens or per-provider strategies: it asks for a valid access token and
 * either gets one or is told the account must be reconnected.
 */
class SocialTokens
{
    public function __construct(protected ConnectorRegistry $registry)
    {
    }

    public function connector(string $provider): ProviderConnector
    {
        return $this->registry->for($provider);
    }

    /**
     * Renew an account's token under a per-account lock, with a double-check so
     * a concurrent renewal (scheduled job vs synchronous call) never refreshes
     * twice. The second arrival re-reads the freshly renewed token instead of
     * calling the provider again, which matters for rotating-refresh-token
     * providers like TikTok where a double refresh invalidates the token.
     *
     * Applies the result on success (single source of truth). Failures are
     * returned for the caller to handle, since the job and the synchronous
     * path react differently to transient failures.
     */
    public function renew(SocialAccount $account): RenewalResult
    {
        $connector = $this->registry->for($account->provider);

        try {
            return Cache::lock($this->lockKey($account), 30)->block(10, function () use ($account, $connector) {
                $account->refresh();

                // Another process may have renewed while we waited for the lock.
                if (! $account->isAccessTokenExpired()) {
                    return RenewalResult::success(
                        accessToken: $account->access_token,
                        expiresAt: $account->expires_at,
                    );
                }

                $result = $connector->renew($account);

                if ($result->succeeded()) {
                    $account->applyRenewal($result, $connector);
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
     * Return a valid access token for the account, renewing synchronously if
     * needed. This is a belt-and-suspenders layer on top of the scheduled job:
     * even if a renewal was missed, a posting attempt still gets a fresh token.
     *
     * @throws NeedsReconnectException
     */
    public function validAccessTokenFor(SocialAccount $account): string
    {
        if (! $account->status->isUsable()) {
            throw NeedsReconnectException::for($account);
        }

        if (! $account->isAccessTokenExpired()) {
            return $account->access_token;
        }

        $connector = $this->registry->for($account->provider);

        if (! $connector->renewalStrategy()->canRenewUnattended() || $account->isRefreshTokenExpired()) {
            $account->markNeedsReconnect('Token expired and cannot be renewed unattended.');

            throw NeedsReconnectException::for($account);
        }

        $result = $this->renew($account);

        if ($result->succeeded()) {
            return $account->fresh()->access_token;
        }

        // Terminal failure: the connection is broken, flag it for the user.
        // Transient failure: leave the status usable so background retries
        // continue, but we still cannot post on this attempt.
        if ($result->outcome === RenewalOutcome::Terminal) {
            $account->markNeedsReconnect($result->reason);
        }

        throw NeedsReconnectException::for($account, $result->reason);
    }

    protected function lockKey(SocialAccount $account): string
    {
        return "social-tokens:renew:{$account->getKey()}";
    }
}
