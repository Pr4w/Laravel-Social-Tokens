<?php

namespace Pr4w\SocialTokens\Console;

use Illuminate\Console\Command;
use Pr4w\SocialTokens\Jobs\RenewCredential;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

class DispatchDueRenewals extends Command
{
    protected $signature = 'social-tokens:dispatch-renewals';

    protected $description = 'Dispatch a renewal job for every credential due for renewal.';

    public function handle(ConnectorRegistry $registry): int
    {
        $count = 0;
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
            ->each(function (SocialToken $token) use (&$count) {
                RenewCredential::dispatch($token);
                $count++;
            });

        $this->info("Dispatched {$count} renewal job(s).");

        return self::SUCCESS;
    }
}
