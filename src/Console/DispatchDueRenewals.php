<?php

namespace Pr4w\SocialTokens\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Queue\SyncQueue;
use Pr4w\SocialTokens\Exceptions\TransientRenewalException;
use Pr4w\SocialTokens\Jobs\RenewCredential;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Pr4w\SocialTokens\Support\Settings;
use Throwable;

/**
 * Dispatches one renewal job per credential whose renew_at has passed. A due
 * credential no active account depends on is not renewed but unscheduled
 * (renew_at null), so that later runs stop re-reading it; a reconnect
 * schedules it again. Each run then announces the Meta data access deadlines
 * that came within their lead time (CredentialDataAccessExpiring): a token
 * that never expires has no renewal to carry that warning. Both come second
 * and cannot stop the renewals: they keep every provider's tokens alive.
 */
class DispatchDueRenewals extends Command
{
    protected $signature = 'social-tokens:dispatch-renewals';

    protected $description = 'Dispatch a renewal job for every credential due for renewal.';

    public function handle(ConnectorRegistry $registry): int
    {
        $count = 0;
        $deferred = 0;
        $failed = 0;
        $stopped = false;
        $inline = null;
        $configured = $registry->configured();

        SocialToken::query()->dueForRenewal()->whereNotIn('provider', $configured)
            ->distinct()->pluck('provider')
            ->each(fn ($provider) => $this->warn("Skipped due credentials for provider [{$provider}]: no connector configured."));

        // A credential no active account depends on (e.g. left behind when a
        // reconnect created a new one) is not worth keeping alive. Walked by id:
        // offset chunks skip rows when jobs run synchronously and move renew_at.
        SocialToken::query()
            ->dueForRenewal()
            ->whereIn('provider', $configured)
            ->inUse()
            ->lazyById()
            ->each(function (SocialToken $token) use (&$count, &$deferred, &$failed, &$stopped, &$inline) {
                // On a sync queue the job runs right here and its failure is
                // rethrown: one credential must never stop the walk.
                try {
                    RenewCredential::dispatch($token);
                    $count++;
                } catch (TransientRenewalException) {
                    // Expected: failed() has already backed it off and logged it.
                    $count++;
                    $deferred++;
                } catch (Throwable $e) {
                    $failed++;
                    report($e);

                    // On a real queue the dispatch itself failed: the queue or
                    // the lock store is down for every credential. Stop at one
                    // report instead of one per credential, each waiting out
                    // its own connect timeout.
                    if (! ($inline ??= $this->runsInline())) {
                        $stopped = true;

                        return false;
                    }
                }
            });

        $this->info("Dispatched {$count} renewal job(s).");

        if ($deferred > 0) {
            $this->warn("{$deferred} renewal(s) failed for now and will be retried later.");
        }

        // After the renewals, and guarded: neither may cost a provider its
        // renewals (the deadline scan's columns do not exist until migration
        // 000009 has run).
        try {
            $unscheduled = $this->unscheduleUnused($configured);
            $unusedChecked = true;

            if ($unscheduled > 0) {
                $this->info("Unscheduled {$unscheduled} credential(s) no active account uses.");
            }
        } catch (Throwable $e) {
            report($e);
            $unusedChecked = false;
            $this->error('Could not unschedule the unused credentials; see the log.');
        }

        try {
            $this->warnDataAccessExpiring();
            $deadlinesChecked = true;
        } catch (Throwable $e) {
            report($e);
            $deadlinesChecked = false;
            $this->error('Could not check the data access deadlines; see the log.');
        }

        if ($stopped) {
            $this->error('Could not queue a renewal job; stopped until the next run. See the log.');

            return self::FAILURE;
        }

        if ($failed > 0) {
            $this->error("{$failed} renewal job(s) failed or could not be dispatched; see the log.");

            return self::FAILURE;
        }

        return $deadlinesChecked && $unusedChecked ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Take the due credentials no active account depends on out of the scan:
     * left due, each run would read them again through inUse()'s subqueries,
     * for good. renew_at null, checked again in the update, so a credential
     * a reconnect rescheduled since the read keeps its date. A reconnect
     * schedules it again (persistCredential()), and so does a renewal on
     * demand (validAccessTokenFor() once its token expires).
     *
     * @param  array<int, string>  $configured
     */
    protected function unscheduleUnused(array $configured): int
    {
        $unscheduled = 0;

        SocialToken::query()
            ->dueForRenewal()
            ->whereIn('provider', $configured)
            ->whereNot(fn (Builder $query) => $query->inUse())
            ->select('id')
            ->chunkById(500, function (Collection $chunk) use (&$unscheduled) {
                $unscheduled += SocialToken::query()->whereKey($chunk->modelKeys())->dueForRenewal()->update(['renew_at' => null]);
            });

        return $unscheduled;
    }

    /**
     * Fire CredentialDataAccessExpiring once for each credential whose data
     * access lapses within the configured lead. Warn-only: nothing is flagged.
     * A listener that throws is reported and does not stop the scan; that
     * credential is not warned again.
     */
    protected function warnDataAccessExpiring(): void
    {
        $lead = Settings::dataAccessWarningLead();

        if ($lead === null) {
            return;
        }

        $warned = 0;

        SocialToken::query()
            ->dataAccessExpiringBefore(now()->add($lead))
            ->lazyById()
            ->each(function (SocialToken $token) use (&$warned) {
                try {
                    $warned += (int) $token->warnDataAccessExpiring();
                } catch (Throwable $e) {
                    report($e);
                }
            });

        if ($warned > 0) {
            $this->info("Warned about {$warned} credential(s) whose data access lapses soon.");
        }
    }

    /**
     * Whether renewal jobs run right here, on a sync connection. Read only
     * once a dispatch has failed, so that a run with nothing to dispatch
     * never resolves the connection.
     */
    protected function runsInline(): bool
    {
        try {
            return app(QueueFactory::class)->connection(config('social-tokens.queue.connection')) instanceof SyncQueue;
        } catch (Throwable) {
            return false;
        }
    }
}
