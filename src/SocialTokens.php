<?php

namespace Pr4w\SocialTokens;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Pr4w\SocialTokens\Contracts\ChecksCredential;
use Pr4w\SocialTokens\Contracts\ProviderConnector;
use Pr4w\SocialTokens\Enums\AccountStatus;
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
            return Cache::lock($this->lockKey($token), 60)->block(10, function () use ($token, $connector) {
                $token->refresh();

                // Revoked or flagged since the caller loaded it: never renew.
                if (! $token->status->isUsable()) {
                    return RenewalResult::terminalFailure("Credential is {$token->status->value}.");
                }

                // Another process may have renewed while we waited for the lock:
                // a successful renewal leaves a valid token whose window has moved
                // into the future. Checking expiry alone is not enough — the job
                // runs at renew_at, days before expiry, and must go through.
                if ($token->access_token !== null && ! $token->isAccessTokenExpired() && ! $token->isDueForRenewal()) {
                    return RenewalResult::success(
                        accessToken: $token->access_token,
                        expiresAt: $token->expires_at,
                    );
                }

                $result = $connector->refreshCredential($token);

                // The app's OAuth client was rejected: every credential of this
                // provider is stuck until the operator fixes the config. Always
                // alert (not gated by log_unknown_errors), once per 15 minutes.
                if ($result->clientError && Cache::add("social-tokens:client-alert:{$token->provider}", true, now()->addMinutes(15))) {
                    Log::critical("[social-tokens] {$token->provider} rejected the app's OAuth client: check its client_id/client_secret. Credentials are kept and retried.", [
                        'provider' => $token->provider,
                        'token_id' => $token->getKey(),
                        'reason' => $result->reason,
                        'context' => $result->context,
                    ]);
                }

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

                    // Revoked while the provider call was in flight: applyRenewal()
                    // stored nothing, and the token must not be handed out.
                    if ($token->status === AccountStatus::Revoked) {
                        return RenewalResult::terminalFailure('Credential was revoked during renewal.');
                    }
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
        // The caller's copy may be stale: the account or its credential may have
        // been revoked or flagged since it was loaded.
        if ($account->exists) {
            try {
                $account->refresh(); // also reloads a loaded `credential` relation
            } catch (ModelNotFoundException) {
                throw NeedsReconnectException::for($account);
            }
        }

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

        // A config problem, not the user's: do not ask them to reconnect (a
        // reconnect would be refused for the same reason).
        if (! $this->registry->has($token->provider)) {
            Log::error('[social-tokens] No connector configured for an expired credential', [
                'provider' => $token->provider,
                'token_id' => $token->getKey(),
            ]);

            throw NeedsReconnectException::transient($account, "No connector configured for provider [{$token->provider}].");
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

        // Terminal failure: the connection is broken, flag the credential. Its
        // accounts' rows are not rewritten; every account it backs reports it
        // through effectiveStatus().
        if ($result->outcome === RenewalOutcome::Terminal) {
            $token->markNeedsReconnect($result->reason);

            throw NeedsReconnectException::for($account, $result->reason);
        }

        // Transient: leave the credential usable so background retries continue,
        // and tell the caller to retry rather than ask the user to reconnect.
        throw NeedsReconnectException::transient($account, $result->reason);
    }

    /**
     * Revoke a credential: tell the provider to invalidate it (best effort), mark
     * the credential Revoked, and cascade to every account it backs. A revoked
     * credential is not usable and is never renewed again.
     */
    public function revoke(SocialToken $token): void
    {
        $this->registry->for($token->provider)->revoke($token);

        $token->markRevoked();

        $token->accounts()->get()->each(fn (SocialAccount $account) => $account->markRevoked());
    }

    /**
     * Report that the provider rejected an account's token at publish time.
     * Flags the account's credential needs_reconnect, once: returns true only
     * when THIS call flagged it (one CredentialNeedsReconnect, the first reason
     * kept). A revoked credential, or one that no longer holds $rejectedToken
     * (the user reconnected meanwhile), is left alone.
     *
     * $terminal: the rejection proves the token is dead (Meta 190/102 or a
     * session subcode, OAuth invalid_grant/invalid_token) and is trusted as is,
     * with no provider call. Otherwise (an ambiguous 401) the provider is asked,
     * when the connector implements ChecksCredential, and the credential is
     * flagged only if it confirms. Pass a reason without secrets.
     */
    public function reportRejected(SocialAccount $account, string $reason, bool $terminal = true, ?string $rejectedToken = null): bool
    {
        // Fresh from the database: the caller's loaded relation may be stale.
        $token = $account->social_token_id !== null ? SocialToken::query()->find($account->social_token_id) : null;

        if ($token === null || $token->status !== AccountStatus::Active) {
            return false;
        }

        if (! $terminal) {
            if (! $this->registry->has($token->provider)) {
                return false;
            }

            $connector = $this->registry->for($token->provider);

            if (! $connector instanceof ChecksCredential) {
                return false;
            }

            $checkedToken = $token->access_token;
            $result = $connector->checkCredential($token);

            if ($result->outcome !== RenewalOutcome::Terminal) {
                if ($result->unknown && config('social-tokens.log_unknown_errors', true)) {
                    Log::error('[social-tokens] Uncatalogued credential check error', [
                        'provider' => $token->provider,
                        'token_id' => $token->getKey(),
                        'reason' => $result->reason,
                        'context' => $result->context,
                    ]);
                }

                return false;
            }

            // The provider's reason ("password changed") is the real cause.
            $reason = $result->reason ?? $reason;
            $rejectedToken ??= $checkedToken;
        }

        return $token->markNeedsReconnectOnce($reason, $rejectedToken);
    }

    protected function lockKey(SocialToken $token): string
    {
        return "social-tokens:renew:{$token->getKey()}";
    }
}
