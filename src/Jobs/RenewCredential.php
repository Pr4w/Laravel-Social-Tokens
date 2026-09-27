<?php

namespace Pr4w\SocialTokens\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Events\CredentialExpiringSoon;
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

        // Handled since dispatch (renewed synchronously, or already warned):
        // nothing to do until the window opens again.
        if (! $token->isDueForRenewal() && ! $token->isAccessTokenExpired()) {
            return;
        }

        $connector = $registry->for($token->provider);

        // No unattended renewal is possible: the expected path for LinkedIn
        // without MDP, or once a refresh token has outlived its own lifetime.
        $blocker = match (true) {
            ! $connector->renewalStrategy()->canRenewUnattended() => 'Provider requires manual re-authorisation.',
            $token->isRefreshTokenExpired() => 'Refresh token has expired.',
            default => null,
        };

        if ($blocker !== null) {
            $this->warnOrFlag($token, $blocker);

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
     * A credential that cannot be renewed still works until it expires, so do
     * not cut it off early: warn now, and move renew_at to the expiry so the
     * next pass lands exactly then — and flags it, if nobody reconnected.
     */
    protected function warnOrFlag(SocialToken $token, string $reason): void
    {
        if ($token->expires_at === null || $token->isAccessTokenExpired()) {
            $token->markNeedsReconnect($reason);

            return;
        }

        $token->renew_at = $token->expires_at;
        $token->save();

        event(new CredentialExpiringSoon($token, $token->expires_at, $reason));
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
