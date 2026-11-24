<?php

namespace Pr4w\SocialTokens\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Pr4w\SocialTokens\Actions\SyncAccountProfile;
use Pr4w\SocialTokens\Contracts\FetchesProfile;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\ProfileSyncOutcome;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

/**
 * Keep every active account's name, handle and picture current with its
 * provider (Actions\SyncAccountProfile): one light call per account, on the
 * providers whose connector reads profiles. Scheduled daily
 * (`profile_sync_schedule`): picture URLs from Meta, LinkedIn and TikTok are
 * signed and expire within days or weeks.
 *
 * A profile it cannot read is left as it is: the run logs one warning per
 * provider with failures, never one per account, and flags nothing.
 */
class SyncProfiles extends Command
{
    protected $signature = 'social-tokens:sync-profiles {--provider=* : Only the accounts of these providers}';

    protected $description = 'Update the name, handle and picture of every active account from its provider.';

    public function handle(ConnectorRegistry $registry, SyncAccountProfile $sync): int
    {
        $providers = array_values(array_filter(
            $registry->configured(),
            fn (string $provider) => $registry->for($provider) instanceof FetchesProfile,
        ));

        if ($only = (array) $this->option('provider')) {
            $providers = array_values(array_intersect($providers, $only));
        }

        $counts = array_fill_keys(array_map(fn (ProfileSyncOutcome $outcome) => $outcome->name, ProfileSyncOutcome::cases()), 0);

        /** @var array<string, array{accounts: int, reason: ?string}> $failures */
        $failures = [];

        SocialAccount::query()
            ->whereIn('provider', $providers)
            ->where('status', AccountStatus::Active->value)
            ->whereRelation('credential', 'status', AccountStatus::Active->value)
            ->lazyById()
            ->each(function (SocialAccount $account) use ($sync, &$counts, &$failures) {
                $result = $sync->handle($account);
                $counts[$result->outcome->name]++;

                if ($result->outcome === ProfileSyncOutcome::Failed) {
                    $failures[$account->provider] ??= ['accounts' => 0, 'reason' => $result->reason];
                    $failures[$account->provider]['accounts']++;
                }
            });

        foreach ($failures as $provider => $failure) {
            Log::warning("[social-tokens] Could not read {$failure['accounts']} {$provider} profile(s); they keep their stored name and picture.", [
                'provider' => $provider,
                'accounts' => $failure['accounts'],
                'reason' => $failure['reason'],
            ]);
        }

        $synced = $counts['Updated'] + $counts['Unchanged'];

        $this->info("Synced {$synced} profile(s): {$counts['Updated']} updated, {$counts['Unchanged']} unchanged.");

        if ($counts['Unavailable'] > 0) {
            $this->line("{$counts['Unavailable']} account(s) did not grant what their profile needs.");
        }

        if ($counts['Failed'] > 0) {
            $this->warn("{$counts['Failed']} profile(s) could not be read; see the log.");
        }

        return self::SUCCESS;
    }
}
