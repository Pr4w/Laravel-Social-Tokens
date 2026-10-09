<?php

namespace Pr4w\SocialTokens\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\Settings;

/**
 * Deletes the credentials nothing will use again (SocialToken::prunable()):
 * revoked or flagged needs_reconnect ones, and those no connected account
 * depends on (left behind by a reconnect, or by accounts the app deleted),
 * once they have not changed for `prune_after_days`. A reconnect stores a
 * credential of its own, so nothing is lost but a token that no longer
 * serves. Accounts are never deleted: the app's own tables may point at
 * them; one whose credential goes keeps its row, without a credential.
 */
class PruneCredentials extends Command
{
    protected $signature = 'social-tokens:prune {--days= : Keep credentials changed within this many days, instead of prune_after_days}';

    protected $description = 'Delete the credentials nothing will use again: revoked, flagged needs_reconnect, or used by no connected account.';

    public function handle(): int
    {
        $option = $this->option('days');

        if ($option !== null && (! is_numeric($option) || (int) $option < 1)) {
            $this->error('--days must be a whole number of days, at least 1.');

            return self::INVALID;
        }

        $days = $option !== null ? (int) $option : Settings::pruneAfterDays();
        $before = now()->subDays($days);
        $deleted = 0;

        SocialToken::query()->prunable($before)->select('id')->chunkById(500, function (Collection $chunk) use ($before, &$deleted) {
            $ids = $chunk->modelKeys();

            // The conditions are checked again by the delete: a credential a
            // reconnect wrote since the read is recent and stays.
            $deleted += SocialToken::query()->whereKey($ids)->prunable($before)->delete();

            // The foreign key does this where it is enforced (not on SQLite
            // without foreign keys): no account points at a deleted row.
            SocialAccount::query()->whereIn('social_token_id', $ids)->whereDoesntHave('credential')->update(['social_token_id' => null]);
        });

        $this->info("Pruned {$deleted} credential(s) unchanged for {$days} day(s).");

        return self::SUCCESS;
    }
}
