<?php

namespace Pr4w\SocialTokens;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Pr4w\SocialTokens\Connectors\AbstractConnector;
use Pr4w\SocialTokens\Contracts\ChecksCredential;
use Pr4w\SocialTokens\Contracts\ProviderConnector;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
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
    use DetectsConcurrencyErrors;

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
     * providers like TikTok and X where a double refresh invalidates the token.
     * The re-read is a locking read, so it also sees a renewal committed after
     * the caller's transaction began, and waits for one not committed yet: as
     * long as the database lets it (MySQL's innodb_lock_wait_timeout, 50
     * seconds by default; on PostgreSQL 30 seconds outside a transaction, and
     * until the holder's transaction ends inside one unless lock_timeout is
     * set). A wait that lasted more than 30 seconds is transient, and so,
     * outside a transaction, is a lock wait timeout or a deadlock. Inside the
     * caller's transaction those errors are thrown: the database may have
     * rolled the transaction back, and only the caller can retry it.
     *
     * Applies the result on success (single source of truth), on the token
     * that was refreshed only: when a reconnect replaced it meanwhile, the
     * result is dropped and the success carries the reconnected token.
     * Failures are returned for the caller to handle, since the job and the
     * synchronous path react differently to transient failures.
     *
     * $token is reloaded in place under the lock. On a Terminal result it
     * holds the token the provider refused: flag it with
     * markNeedsReconnectOnce($result->reason, $token->access_token), so that
     * a credential reconnected meanwhile is not flagged.
     *
     * Call it outside your own database transaction: there the row stays
     * locked until the transaction ends, and a rollback discards the renewed
     * token, which for a single-use refresh token (TikTok, X) the provider
     * has already retired. Such a renewal logs a warning.
     *
     * $minValiditySeconds (floor 30): a token that will not stay valid that long
     * is renewed even if another process just stored it.
     *
     * @throws InvalidArgumentException for a credential never saved in the
     *                                  social_tokens table (use the connector
     *                                  directly to refresh a token held elsewhere).
     * @throws ModelNotFoundException<SocialToken> for a credential deleted
     *                                             while it waited or renewed.
     * @throws QueryException inside the caller's transaction, when the
     *                        database did not grant the row lock (a nested
     *                        DB::transaction() turns a deadlock into a
     *                        DeadlockException).
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
                $waitingSince = now();

                try {
                    $this->lockForRenewal($token);
                } catch (QueryException $e) {
                    // A transaction held the row past the database's lock wait,
                    // or deadlocked with this read. Inside the caller's own
                    // transaction that is not ours to absorb: a MySQL deadlock
                    // has rolled the whole transaction back, and any PostgreSQL
                    // error aborts it. A transient result would let the caller
                    // carry on and commit the rest of its work alone, so its
                    // DB::transaction() gets the error, to roll back or retry.
                    if ($token->getConnection()->transactionLevel() === 0 && $this->lockWasRefused($e)) {
                        return RenewalResult::transientFailure('Could not lock the credential for renewal.');
                    }

                    throw $e;
                }

                // A transaction held the row for a long time: the 60-second lock
                // could lapse during the provider call and let a second renewal
                // of the same refresh token in.
                if ($waitingSince->diffInSeconds(now(), true) > 30) {
                    return RenewalResult::transientFailure('Could not lock the credential for renewal.');
                }

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

                if ($token->renewalStrategyWith($connector) === RenewalStrategy::RotatingRefreshToken
                    && $token->getConnection()->transactionLevel() > 0) {
                    Log::warning('[social-tokens] Renewing a single-use refresh token inside a database transaction', [
                        'provider' => $token->provider,
                        'token_id' => $token->getKey(),
                        'hint' => 'A rollback discards the new refresh token and the credential dies at its next renewal: ask for the token before opening the transaction.',
                    ]);
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

                if ($result->succeeded() && ! $token->tryApplyRenewal($result, $connector)) {
                    // Nothing stored, and $token now holds the row as it is.
                    // Revoked while the provider call was in flight: the token
                    // must not be handed out.
                    if ($token->status === AccountStatus::Revoked) {
                        return RenewalResult::terminalFailure('Credential was revoked during renewal.');
                    }

                    if (! $token->status->isUsable() || $token->access_token === null) {
                        return RenewalResult::terminalFailure("Credential is {$token->status->value}.");
                    }

                    // Reconnected meanwhile: the new grant's token, not the one
                    // renewed from the old grant.
                    return RenewalResult::success(
                        accessToken: $token->access_token,
                        expiresAt: $token->expires_at,
                    );
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
     * The locking re-read under the renewal lock. PostgreSQL waits for a row
     * lock until the holder's transaction ends unless lock_timeout is set, so
     * a request or a worker would hang on a credential another process keeps
     * locked through a long transaction (MySQL gives up at
     * innodb_lock_wait_timeout). Outside the caller's transaction the wait is
     * bounded at 30 seconds, in a transaction of its own since the setting
     * ends with it; a lock_timeout the app set is kept. Inside the caller's
     * transaction the setting stays the caller's.
     */
    protected function lockForRenewal(SocialToken $token): void
    {
        $connection = $token->getConnection();

        if ($connection->getDriverName() !== 'pgsql' || $connection->transactionLevel() > 0) {
            $token->refreshForUpdate();

            return;
        }

        $connection->transaction(function () use ($connection, $token) {
            $connection->statement("select set_config('lock_timeout', '30s', true) where current_setting('lock_timeout') = '0'");
            $token->refreshForUpdate();
        });
    }

    /**
     * The database did not grant the row lock: a lock wait timeout or a
     * deadlock, or PostgreSQL's lock_timeout (SQLSTATE 55P03), which Laravel
     * does not count as a concurrency error.
     */
    protected function lockWasRefused(QueryException $e): bool
    {
        return $this->causedByConcurrencyError($e) || ($e->errorInfo[0] ?? $e->getCode()) === '55P03';
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
     * @throws QueryException inside the caller's transaction, when renewing
     *                        could not lock the credential (see renewCredential()).
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

        if (! $token->renewalStrategyWith($connector)->canRenewUnattended() || $token->isRefreshTokenExpired()) {
            // Cannot be renewed, but still valid: hand it out rather than cut it
            // off early (the renewal job warns the user ahead of the expiry).
            if ($token->access_token !== null && ! $token->isAccessTokenExpired()) {
                return $token->access_token;
            }

            $token->markNeedsReconnect('Token expired and cannot be renewed unattended.');

            throw NeedsReconnectException::for($account);
        }

        $result = $this->renewCredential($token, $minValiditySeconds);

        // The token renewCredential() stored, or the one another process
        // stored meanwhile: a plain re-read inside the caller's transaction
        // could return an older snapshot.
        if ($result->succeeded() && $result->accessToken !== null) {
            return $result->accessToken;
        }

        // Terminal failure: the connection is broken, flag the credential. Its
        // accounts' rows are not rewritten; every account it backs reports it
        // through effectiveStatus().
        if ($result->outcome === RenewalOutcome::Terminal) {
            // Only if it still holds the refused token: a reconnect that
            // landed meanwhile is a working credential, worth a retry.
            if (! $token->markNeedsReconnectOnce($result->reason, (string) $token->access_token)
                && $token->fresh()?->status->isUsable()) {
                throw NeedsReconnectException::transient($account, 'The credential was reconnected during renewal; retry.');
            }

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
        // the app (Google, TikTok), so skip it while another credential still
        // holds an active grant for the same user, under another owner or for
        // another purpose (the LinkedIn organizations next to the member's
        // personal profile): revoke locally only.
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
     * Instagram accounts of the same Facebook user, the other organizations of
     * a LinkedIn member) keep posting; the credential itself is revoked, at the
     * provider too, only when its last connected account goes. "Connected" means
     * not revoked: a sibling flagged needs_reconnect keeps the credential alive.
     * A LinkedIn member's personal profile has its own credential, apart from
     * the organizations' (purpose 'organizations'), so disconnecting it revokes
     * that one at once, and the organizations keep theirs.
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
     * Revoke, locally, everything one external user's grant backs, under every
     * owner: the credentials they hold, the accounts recorded under them
     * (provider_holder_id: a Facebook user's Pages and Instagram accounts),
     * the credentials those accounts post with (each Page's own token), and
     * every account posting with one of those credentials. For when the
     * provider says the user removed the app (Meta's Deauthorize callback,
     * Threads' Uninstall): the grant is already gone there, so the provider is
     * not called. CredentialRevoked and AccountRevoked fire as for revoke(),
     * once per row that changes. A later reconnect makes the rows active again.
     *
     * $provider is a connector key. A connector whose credentials live under
     * another key covers that key's accounts too: 'facebook' (or 'instagram')
     * reaches the user's Facebook Pages and their Instagram accounts. Every
     * row is revoked even when a listener throws; the first exception is
     * rethrown after.
     *
     * @return EloquentCollection<int, SocialAccount> The accounts found, revoked.
     */
    public function revokeExternalUser(string $provider, string $externalId): EloquentCollection
    {
        [$credentials, $accounts] = $this->externalUserRows($provider, $externalId);

        $this->revokeEach([...$credentials->all(), ...$accounts->all()]);

        return $accounts;
    }

    /**
     * Delete one external user's tokens, under every owner: the credentials
     * revokeExternalUser() would revoke are deleted, and the accounts it
     * would revoke are revoked (AccountRevoked fires) and keep their rows,
     * without a credential, since your own tables may point at them. No
     * CredentialRevoked fires for a deleted credential. The tokens are
     * deleted even when a listener throws; the first exception is rethrown
     * after.
     *
     * For a data deletion request, the account rows (name, avatar, profile)
     * and whatever your app keeps about these accounts are yours to delete or
     * anonymise: the returned accounts say which. When a listener throws,
     * nothing is returned, so $deleted receives them as soon as the tokens
     * are deleted, before that exception is rethrown: what must follow a
     * deletion whatever a listener did (HandleDataDeletionCallback's answer
     * to Meta) can rely on it. It is not called when the deletion itself
     * fails.
     *
     * @param  (Closure(EloquentCollection<int, SocialAccount>): void)|null  $deleted
     * @return EloquentCollection<int, SocialAccount> The accounts found, revoked and without a credential.
     */
    public function purgeExternalUser(string $provider, string $externalId, ?Closure $deleted = null): EloquentCollection
    {
        [$credentials, $accounts] = $this->externalUserRows($provider, $externalId);

        try {
            $this->revokeEach($accounts->all());
        } finally {
            if ($credentials->isNotEmpty()) {
                // Explicitly, rather than through the foreign key: SQLite
                // enforces it only when foreign keys are on.
                SocialAccount::query()->whereIn('social_token_id', $credentials->modelKeys())->update(['social_token_id' => null]);
                SocialToken::query()->whereKey($credentials->modelKeys())->delete();

                $accounts->each(fn (SocialAccount $account) => $account->setAttribute('social_token_id', null)
                    ->syncOriginalAttribute('social_token_id')
                    ->unsetRelation('credential'));
            }

            if ($deleted !== null) {
                $deleted($accounts);
            }
        }

        return $accounts;
    }

    /**
     * The rows one external user's grant backs, across owners (see
     * revokeExternalUser()).
     *
     * @return array{0: EloquentCollection<int, SocialToken>, 1: EloquentCollection<int, SocialAccount>}
     */
    protected function externalUserRows(string $provider, string $externalId): array
    {
        $credentialProvider = $this->registry->has($provider)
            ? $this->registry->for($provider)->credentialProvider()
            : $provider;

        $accountProviders = collect($this->registry->configured())
            ->filter(fn (string $key) => $this->registry->for($key)->credentialProvider() === $credentialProvider)
            ->merge([$provider, $credentialProvider])
            ->unique()
            ->values()
            ->all();

        $held = SocialAccount::query()
            ->whereIn('provider', $accountProviders)
            ->where('provider_holder_id', $externalId)
            ->get();

        $credentials = SocialToken::query()
            ->where(fn ($query) => $query->where('provider', $credentialProvider)->where('provider_holder_id', $externalId))
            ->orWhereIn('id', $held->pluck('social_token_id')->filter()->unique()->values()->all())
            ->get();

        $accounts = $held->merge(
            SocialAccount::query()->whereIn('social_token_id', $credentials->modelKeys())->get()
        )->values();

        return [$credentials, $accounts];
    }

    /**
     * markRevoked() on every row, whatever a listener throws; the first
     * exception is rethrown once all have been tried.
     *
     * @param  array<int, SocialToken|SocialAccount>  $rows
     */
    protected function revokeEach(array $rows): void
    {
        $failure = null;

        foreach ($rows as $row) {
            try {
                $row->markRevoked();
            } catch (Throwable $e) {
                $failure ??= $e;
            }
        }

        if ($failure !== null) {
            throw $failure;
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
     * validAccessTokenFor() renews it. LinkedIn with refresh tokens can check
     * its token, so report its revoked token with $terminal false.
     *
     * Otherwise (an ambiguous 401, a bare Meta 190/102) the provider is asked,
     * when the connector implements ChecksCredential, and only a definitive
     * answer flags. A plain rejection is recorded as one check-static strike
     * against the token, and only a check-static run that still sees the
     * rejection flags it: with check_static_schedule null, nothing does unless
     * the app runs the command. Reports never confirm each other, since
     * publish retries seconds apart would all see the same provider incident.
     * Without ChecksCredential this does nothing. Pass a reason without
     * secrets.
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
