<?php

namespace Pr4w\SocialTokens;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Pr4w\SocialTokens\Connectors\AbstractConnector;
use Pr4w\SocialTokens\Contracts\ChecksCredential;
use Pr4w\SocialTokens\Contracts\ProviderConnector;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Exceptions\NeedsReconnectException;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Pr4w\SocialTokens\Support\RenewalResult;
use Pr4w\SocialTokens\Support\Settings;
use Throwable;

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
     *
     * $minValiditySeconds (floor 30): a token that will not stay valid that long
     * is renewed even if another process just stored it.
     *
     * @throws InvalidArgumentException for a credential never saved in the
     *                                  social_tokens table (use the connector
     *                                  directly to refresh a token held elsewhere).
     */
    public function renewCredential(SocialToken $token, int $minValiditySeconds = 30): RenewalResult
    {
        // The lock, the double-check and applyRenewal() all key on the stored
        // row: an unsaved token would share one lock key and be INSERTed.
        if (! $token->exists) {
            throw new InvalidArgumentException(
                'renewCredential() only renews a credential saved in the social_tokens table. '
                .'To refresh a token held elsewhere, call connector($provider)->refreshCredential() and persist the result yourself.'
            );
        }

        $minValiditySeconds = max(30, $minValiditySeconds);
        $connector = $this->registry->for($token->provider);

        try {
            return Cache::lock($this->lockKey($token), 60)->block(10, function () use ($token, $connector, $minValiditySeconds) {
                $token->refresh();

                // Revoked or flagged since the caller loaded it: never renew.
                if (! $token->status->isUsable()) {
                    return RenewalResult::terminalFailure("Credential is {$token->status->value}.");
                }

                // Another process may have renewed while we waited for the lock:
                // a successful renewal leaves a valid token whose window has moved
                // into the future. Checking expiry alone is not enough — the job
                // runs at renew_at, days before expiry, and must go through.
                if ($token->access_token !== null && ! $token->isAccessTokenExpired($minValiditySeconds) && ! $token->isDueForRenewal()) {
                    return RenewalResult::success(
                        accessToken: $token->access_token,
                        expiresAt: $token->expires_at,
                    );
                }

                $result = $connector->refreshCredential($token);

                if ($result->clientError) {
                    $this->alertClientRejected($token, $result);
                }

                if ($result->unknown && Settings::logUnknownErrors()) {
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
     * $minValiditySeconds: how long the caller will hold the token (e.g. 20
     * minutes while polling an Instagram container). A token expiring sooner is
     * renewed first; keep it under the provider's token lifetime, or every call
     * refreshes. Never less than 30 seconds.
     *
     * @throws NeedsReconnectException
     */
    public function validAccessTokenFor(SocialAccount $account, int $minValiditySeconds = 30): string
    {
        // 30 seconds is a floor: a token is never handed out closer to expiry.
        $minValiditySeconds = max(30, $minValiditySeconds);

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

        if (! $token->isAccessTokenExpired($minValiditySeconds) && $token->access_token !== null) {
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
            // Cannot be renewed, but still valid: hand it out rather than cut it
            // off early (the renewal job warns the user ahead of the expiry).
            if ($token->access_token !== null && ! $token->isAccessTokenExpired()) {
                return $token->access_token;
            }

            $token->markNeedsReconnect('Token expired and cannot be renewed unattended.');

            throw NeedsReconnectException::for($account);
        }

        $result = $this->renewCredential($token, $minValiditySeconds);

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
     * Revoke a credential: mark it Revoked, cascade to every account it backs,
     * then tell the provider to invalidate it (best effort). A revoked
     * credential is not usable and is never renewed again.
     *
     * The local revocation comes first and does not depend on the provider
     * call: the user's decision stands even when that call cannot be made (a
     * token that no longer decrypts after an APP_KEY change, a provider down,
     * a connector that throws). A failed provider call is logged.
     *
     * Nor does the provider call depend on the revocation listeners, which run
     * synchronously before it. Once the row is revoked, a CredentialRevoked
     * listener that throws (or a queued one whose push fails) still lets the
     * cascade run, and any revocation listener that throws still lets the
     * provider call happen; its exception then reaches the caller. A retried
     * disconnect() would not make that call: the credential is revoked by then.
     */
    public function revoke(SocialToken $token): void
    {
        // Revoking at the provider can end the external user's whole consent for
        // the app (Google, TikTok), so skip it while another owner still holds
        // an active grant for the same user: revoke locally only.
        $sharedGrant = SocialToken::query()
            ->whereKeyNot($token->getKey())
            ->where('provider', $token->provider)
            ->where('provider_holder_id', $token->provider_holder_id)
            ->where('status', AccountStatus::Active->value)
            ->exists();

        // The provider gets the token values as they are now: a CredentialRevoked
        // listener may wipe them from the instance it receives, this one.
        $atProvider = clone $token;

        try {
            $token->markRevoked();
        } finally {
            // Revoked in memory only when the row is: the status is set before
            // CredentialRevoked fires, never when the update itself failed.
            if ($token->status === AccountStatus::Revoked) {
                try {
                    $token->accounts()->get()->each(fn (SocialAccount $account) => $account->markRevoked());
                } finally {
                    if (! $sharedGrant && $this->registry->has($token->provider)) {
                        $this->revokeAtProvider($atProvider);
                    }
                }
            }
        }
    }

    /**
     * Best effort: ProviderConnector::revoke() must not throw, but a custom
     * connector, or a token that cannot be decrypted, may. Never rethrown.
     */
    protected function revokeAtProvider(SocialToken $token): void
    {
        try {
            $this->registry->for($token->provider)->revoke($token);
        } catch (Throwable $e) {
            Log::warning("[social-tokens] {$token->provider} token revocation failed; the credential is revoked locally", [
                'provider' => $token->provider,
                'token_id' => $token->getKey(),
                'exception' => $e::class,
                'message' => AbstractConnector::redactQueryStrings($e->getMessage()),
            ]);
        }
    }

    /**
     * Disconnect ONE account. Accounts sharing its credential (the other
     * Instagram accounts of the same Facebook user, a LinkedIn member's profile
     * and organizations) keep posting; the credential itself is revoked, at the
     * provider too, only when its last connected account goes. "Connected" means
     * not revoked: a sibling flagged needs_reconnect keeps the credential alive.
     *
     * An AccountRevoked listener that throws does not stop the credential's
     * revocation: the account row is revoked by then, so a retried disconnect()
     * would find nothing to do on it. The exception reaches the caller after.
     */
    public function disconnect(SocialAccount $account): void
    {
        try {
            $account->markRevoked();
        } finally {
            if ($account->status === AccountStatus::Revoked) {
                $this->revokeCredentialOnceUnused($account);
            }
        }
    }

    /**
     * Revoke a disconnected account's credential when no other connected
     * account uses it.
     */
    protected function revokeCredentialOnceUnused(SocialAccount $account): void
    {
        $token = $account->credential()->first();

        if ($token === null || $token->status === AccountStatus::Revoked) {
            return;
        }

        $stillConnected = $token->accounts()
            ->whereKeyNot($account->getKey())
            ->where('status', '!=', AccountStatus::Revoked->value)
            ->exists();

        if (! $stillConnected) {
            $this->revoke($token); // account transitions are idempotent: no second AccountRevoked
        }
    }

    /**
     * Report that the provider rejected an account's token at publish time.
     * Flags the account's credential needs_reconnect, once: returns true only
     * when THIS call flagged it (one CredentialNeedsReconnect, the first reason
     * kept). A revoked credential, or one that no longer holds $rejectedToken
     * (the user reconnected meanwhile), is left alone.
     *
     * $terminal: the rejection itself proves the credential is dead and is
     * trusted as is, with no provider call. Pass it only for a definitive
     * error: a Meta 190/102 with a session subcode (password changed, app
     * removed), or LinkedIn without refresh tokens saying the token was
     * revoked or expired. Not for an OAuth invalid_token from a provider with
     * refresh tokens (Google, TikTok, X): its access token merely expired, and
     * validAccessTokenFor() renews it.
     *
     * Otherwise (an ambiguous 401, a bare Meta 190/102) the provider is asked,
     * when the connector implements ChecksCredential, and only a definitive
     * answer flags. A plain rejection is recorded as one check-static strike
     * against the token: the next check-static run flags it if the provider
     * still rejects it. Reports never confirm each other, since publish
     * retries seconds apart would all see the same provider incident. Without
     * ChecksCredential this does nothing. Pass a reason without secrets.
     */
    public function reportRejected(
        SocialAccount $account,
        string $reason,
        bool $terminal = true,
        #[\SensitiveParameter] ?string $rejectedToken = null,
    ): bool {
        // Fresh from the database: the caller's loaded relation may be stale.
        $token = $account->social_token_id !== null ? SocialToken::query()->find($account->social_token_id) : null;

        if ($token === null || $token->status !== AccountStatus::Active) {
            return false;
        }

        if ($terminal) {
            return $token->markNeedsReconnectOnce($reason, $rejectedToken);
        }

        if (! $this->registry->has($token->provider)) {
            return false;
        }

        $connector = $this->registry->for($token->provider);

        if (! $connector instanceof ChecksCredential) {
            return false;
        }

        $checkedToken = (string) $token->access_token;
        $cipher = $token->getRawOriginal('access_token');

        // A rejection of a token a reconnect already replaced says nothing
        // about the new one.
        if ($rejectedToken !== null && ! hash_equals($checkedToken, $rejectedToken)) {
            return false;
        }

        $result = $connector->checkCredential($token);

        if ($result->clientError) {
            $this->alertClientRejected($token, $result);
        }

        if ($result->outcome !== RenewalOutcome::Terminal) {
            if ($result->unknown && Settings::logUnknownErrors()) {
                Log::error('[social-tokens] Uncatalogued credential check error', [
                    'provider' => $token->provider,
                    'token_id' => $token->getKey(),
                    'reason' => $result->reason,
                    'context' => $result->context,
                ]);
            }

            return false;
        }

        if (! ($result->context['definitive'] ?? false)) {
            // One strike against the token that was checked, left for the next
            // check-static run to confirm or clear.
            SocialToken::query()
                ->whereKey($token->getKey())
                ->where('status', AccountStatus::Active->value)
                ->where('access_token', $cipher)
                ->where('failed_checks', '<', 1)
                ->update(['failed_checks' => 1]);

            return false;
        }

        // The provider's reason ("password changed") is the real cause.
        return $token->markNeedsReconnectOnce($result->reason ?? $reason, $checkedToken);
    }

    /**
     * The app's OAuth client was rejected: every credential of this provider
     * is stuck until the operator fixes the config. Always alert (not gated
     * by log_unknown_errors), once per provider per 15 minutes.
     */
    protected function alertClientRejected(SocialToken $token, RenewalResult $result): void
    {
        if (! Cache::add("social-tokens:client-alert:{$token->provider}", true, now()->addMinutes(15))) {
            return;
        }

        Log::critical("[social-tokens] {$token->provider} rejected the app's OAuth client: check its client_id/client_secret. Credentials are kept and retried.", [
            'provider' => $token->provider,
            'token_id' => $token->getKey(),
            'reason' => $result->reason,
            'context' => $result->context,
        ]);
    }

    protected function lockKey(SocialToken $token): string
    {
        return "social-tokens:renew:{$token->getKey()}";
    }
}
