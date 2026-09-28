<?php

namespace Pr4w\SocialTokens\Jobs;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
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

    /**
     * A credential deleted before the job runs drops the job quietly. Must be a
     * property default: the queue reads it from the class, not the instance.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public SocialToken $token)
    {
        $this->onConnection(config('social-tokens.queue.connection'));
        $this->onQueue(config('social-tokens.queue.queue'));
    }

    public function uniqueId(): string
    {
        return 'social-tokens-renew-'.$this->token->getKey();
    }

    /**
     * Hold the uniqueness lock across every configured retry, so a dispatcher
     * run while the job is still retrying never stacks a second one.
     */
    public function uniqueFor(): int
    {
        $backoff = $this->backoff();
        $span = 0;

        for ($attempt = 1; $attempt < $this->tries(); $attempt++) {
            $span += (int) ($backoff[$attempt - 1] ?? (end($backoff) ?: 0));
        }

        return $span + 300; // margin for the tries themselves (10s lock wait + HTTP timeouts)
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

        // The provider just rejected the app's OAuth client: skip the call until
        // the breaker lapses rather than send N doomed requests per tick.
        if (Cache::has($this->clientRejectedKey($token))) {
            return;
        }

        // Locked + double-checked renewal. On success the result is already
        // applied to the credential inside renewCredential().
        $result = $tokens->renewCredential($token);

        // A misconfigured app client is the operator's problem (already logged
        // as critical), never the member's: no retry storm, no flag. renew_at
        // stays in the past, so a later dispatcher run tries again.
        if ($result->clientError) {
            Cache::put($this->clientRejectedKey($token), true, now()->addMinutes(15));

            return;
        }

        // Transient: throw so the queue retries with backoff. Once retries are
        // exhausted, failed() backs renew_at off, or flags the credential if it
        // truly can no longer be renewed.
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

        $reason = 'Renewal failed after retries: '.$exception->getMessage();

        // Only a credential that can no longer be renewed at all needs the user.
        // An outage on a refresh-token provider is not that, even once the access
        // token has expired: the refresh token still works when it comes back.
        if ($token->isAccessTokenExpired(0) && ! $this->canStillRenew($token)) {
            $token->markNeedsReconnect($reason);

            return;
        }

        // Stay active and back off at the credential level, so the dispatcher
        // does not re-dispatch it on every tick. last_error records the outage;
        // the status, not last_error, says whether the credential is broken.
        $token->renew_at = $this->retryAt($token);
        $token->last_error = $reason;
        $token->save();
    }

    protected function clientRejectedKey(SocialToken $token): string
    {
        return "social-tokens:client-rejected:{$token->provider}";
    }

    protected function canStillRenew(SocialToken $token): bool
    {
        $registry = app(ConnectorRegistry::class);

        if (! $registry->has($token->provider)) {
            return true; // a config problem, not something the user can fix by reconnecting
        }

        $strategy = $registry->for($token->provider)->renewalStrategy();

        return $strategy->canRenewUnattended()
            && ! $strategy->requiresLiveAccessToken()
            && ! $token->isRefreshTokenExpired();
    }

    /**
     * Soon when the token has already expired; otherwise a quarter of the time
     * left, between 5 and 60 minutes, and never past the expiry.
     */
    protected function retryAt(SocialToken $token): CarbonInterface
    {
        if ($token->expires_at === null || $token->isAccessTokenExpired(0)) {
            return now()->addMinutes(15);
        }

        $remaining = (int) now()->diffInMinutes($token->expires_at, true);
        $minutes = max(5, min(60, intdiv($remaining, 4)));

        return now()->addMinutes($minutes)->min($token->expires_at);
    }
}
