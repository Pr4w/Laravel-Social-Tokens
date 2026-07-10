<?php

namespace Pr4w\SocialTokens\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use RuntimeException;
use Throwable;

/**
 * Renews a single account's token. One job per account so a failure on one
 * provider never blocks the others, and so retry/backoff are per account.
 *
 * ShouldBeUnique keeps a second job for the same account from being queued
 * while one is pending, and the actual renewal runs under a per-account lock
 * (see SocialTokens::renew) so it can never collide with a synchronous renewal.
 */
class RenewAccountToken implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public SocialAccount $account)
    {
        $this->onConnection(config('social-tokens.queue.connection'));
        $this->onQueue(config('social-tokens.queue.queue'));
    }

    public function uniqueId(): string
    {
        return 'social-tokens-renew-' . $this->account->getKey();
    }

    public function uniqueFor(): int
    {
        return 600; // release the uniqueness lock after 10 min as a safety net
    }

    public function tries(): int
    {
        return (int) config('social-tokens.queue.tries', 4);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return config('social-tokens.queue.backoff', [60, 300, 900]);
    }

    public function handle(SocialTokens $tokens, ConnectorRegistry $registry): void
    {
        $account = $this->account->fresh();

        if ($account === null || ! $account->status->isUsable()) {
            return; // already reconnected, revoked, or deleted
        }

        $connector = $registry->for($account->provider);

        // Providers that cannot renew unattended: flag for reconnection ahead
        // of expiry instead of attempting a doomed call. This is the expected
        // path for LinkedIn without MDP, not an error.
        if (! $connector->renewalStrategy()->canRenewUnattended()) {
            $account->markNeedsReconnect('Provider requires manual re-authorisation.');

            return;
        }

        // A dead refresh token can never produce a new access token.
        if ($account->isRefreshTokenExpired()) {
            $account->markNeedsReconnect('Refresh token has expired.');

            return;
        }

        // Locked + double-checked renewal. On success the result is already
        // applied to the account inside renew().
        $result = $tokens->renew($account);

        // Transient: throw so the queue retries with backoff while there is still
        // a usable window. Once the token has actually expired and retries are
        // exhausted, the failed() hook escalates to needs_reconnect.
        match ($result->outcome) {
            RenewalOutcome::Success => null,
            RenewalOutcome::Terminal => $account->markNeedsReconnect($result->reason),
            RenewalOutcome::Transient => throw new RuntimeException(
                'Transient renewal failure: '.($result->reason ?? 'unknown')
            ),
        };
    }

    /**
     * Called by the queue after the final attempt fails.
     */
    public function failed(Throwable $exception): void
    {
        $account = $this->account->fresh();

        if ($account === null || ! $account->status->isUsable()) {
            return;
        }

        // If the token is already expired, the connection is effectively broken
        // and needs human attention. Otherwise leave it active: a later run of
        // the dispatcher will try again while the window is still open.
        if ($account->isAccessTokenExpired(0)) {
            $account->markNeedsReconnect('Renewal failed after retries: ' . $exception->getMessage());
        }
    }
}
