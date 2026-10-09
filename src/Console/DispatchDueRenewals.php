<?php

namespace Pr4w\SocialTokens\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\SyncQueue;
use Pr4w\SocialTokens\Exceptions\TransientRenewalException;
use Pr4w\SocialTokens\Jobs\RenewCredential;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Throwable;

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

        if ($stopped) {
            $this->error('Could not queue a renewal job; stopped until the next run. See the log.');

            return self::FAILURE;
        }

        if ($failed > 0) {
            $this->error("{$failed} renewal job(s) failed or could not be dispatched; see the log.");

            return self::FAILURE;
        }

        return self::SUCCESS;
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
