<?php

namespace Pr4w\SocialTokens\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use RuntimeException;
use Throwable;

/**
 * Renews a single credential. One job per credential so a failure on one never
 * blocks the others, and so retry/backoff are per credential. Every account that
 * shares the credential is kept alive by this one renewal.
 *
 * ShouldBeUnique keeps a second job for the same credential from being queued
 * while one is pending, and the renewal runs under a per-credential lock (see
 * SocialTokens::renewCredential) so it can never collide with a synchronous one.
 */
class RenewCredential implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public SocialToken $token)
    {
        $this->onConnection(config('social-tokens.queue.connection'));
        $this->onQueue(config('social-tokens.queue.queue'));
    }

    public function uniqueId(): string
    {
        return 'social-tokens-renew-'.$this->token->getKey();
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
        $token = $this->token->fresh();

        if ($token === null || ! $token->status->isUsable()) {
            return; // already reconnected, revoked, or deleted
        }

        $connector = $registry->for($token->provider);

        // Providers that cannot renew unattended: flag for reconnection ahead of
        // expiry instead of attempting a doomed call. This is the expected path
        // for LinkedIn without MDP, not an error.
        if (! $connector->renewalStrategy()->canRenewUnattended()) {
            $token->markNeedsReconnect('Provider requires manual re-authorisation.');

            return;
        }

        // A dead refresh token can never produce a new access token.
        if ($token->isRefreshTokenExpired()) {
            $token->markNeedsReconnect('Refresh token has expired.');

            return;
        }

        // Locked + double-checked renewal. On success the result is already
        // applied to the credential inside renewCredential().
        $result = $tokens->renewCredential($token);

        // Transient: throw so the queue retries with backoff while there is still
        // a usable window. Once the token has actually expired and retries are
        // exhausted, the failed() hook escalates to needs_reconnect.
        match ($result->outcome) {
            RenewalOutcome::Success => null,
            RenewalOutcome::Terminal => $token->markNeedsReconnect($result->reason),
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
        $token = $this->token->fresh();

        if ($token === null || ! $token->status->isUsable()) {
            return;
        }

        // If the token is already expired, the connection is effectively broken
        // and needs human attention. Otherwise leave it active: a later run of
        // the dispatcher will try again while the window is still open.
        if ($token->isAccessTokenExpired(0)) {
            $token->markNeedsReconnect('Renewal failed after retries: '.$exception->getMessage());
        }
    }
}
