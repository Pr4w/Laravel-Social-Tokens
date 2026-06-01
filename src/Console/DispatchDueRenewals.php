<?php

namespace Pr4w\SocialTokens\Console;

use Illuminate\Console\Command;
use Pr4w\SocialTokens\Jobs\RenewAccountToken;
use Pr4w\SocialTokens\Models\SocialAccount;

class DispatchDueRenewals extends Command
{
    protected $signature = 'social-tokens:dispatch-renewals';

    protected $description = 'Dispatch a renewal job for every account whose token is due for renewal.';

    public function handle(): int
    {
        $count = 0;

        SocialAccount::query()
            ->dueForRenewal()
            ->each(function (SocialAccount $account) use (&$count) {
                RenewAccountToken::dispatch($account);
                $count++;
            });

        $this->info("Dispatched {$count} renewal job(s).");

        return self::SUCCESS;
    }
}
