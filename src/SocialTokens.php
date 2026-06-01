<?php

namespace Pr4w\SocialTokens;

use Pr4w\SocialTokens\Contracts\ProviderConnector;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Exceptions\NeedsReconnectException;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

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

        $result = $connector->renew($account);

        if ($result->succeeded()) {
            $account->applyRenewal($result, $connector);

            return $account->access_token;
        }

        // Terminal failure: the connection is broken, flag it for the user.
        // Transient failure: leave the status usable so background retries
        // continue, but we still cannot post on this attempt.
        if ($result->outcome === RenewalOutcome::Terminal) {
            $account->markNeedsReconnect($result->reason);
        }

        throw NeedsReconnectException::for($account, $result->reason);
    }
}
