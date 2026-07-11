<?php

namespace Pr4w\SocialTokens\Console;

use Illuminate\Console\Command;
use Pr4w\SocialTokens\Jobs\RenewCredential;
use Pr4w\SocialTokens\Models\SocialToken;

class DispatchDueRenewals extends Command
{
    protected $signature = 'social-tokens:dispatch-renewals';

    protected $description = 'Dispatch a renewal job for every credential due for renewal.';

    public function handle(): int
    {
        $count = 0;

        SocialToken::query()
            ->dueForRenewal()
            ->each(function (SocialToken $token) use (&$count) {
                RenewCredential::dispatch($token);
                $count++;
            });

        $this->info("Dispatched {$count} renewal job(s).");

        return self::SUCCESS;
    }
}
