<?php

namespace Pr4w\SocialTokens\Actions\Concerns;

use Illuminate\Database\Eloquent\Model;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;

/**
 * A fan-out connection stores every target the provider lists, and a
 * reconnect of the same login lists the ones the user disconnected too: it
 * is to refresh the token, or to add a Page, not to bring those back. So the
 * actions leave them out (no status change, no AccountConnected, not
 * returned) unless the caller asks to restore them.
 */
trait KeepsDisconnectedAccountsOut
{
    /**
     * The ids, among $ids, of the owner's accounts of $provider the user
     * disconnected (SocialAccount::wasDisconnected()).
     *
     * @param  array<int, string>  $ids
     * @return array<int, string>
     */
    protected function disconnectedAmong(string $provider, array $ids, ?Model $owner): array
    {
        if ($ids === []) {
            return [];
        }

        return SocialAccount::query()
            ->ownedBy($owner)
            ->where('provider', $provider)
            ->whereIn('provider_user_id', $ids)
            ->where('status', AccountStatus::Revoked->value)
            ->get()
            ->filter(fn (SocialAccount $account) => $account->wasDisconnected())
            ->map(fn (SocialAccount $account) => (string) $account->provider_user_id)
            ->values()
            ->all();
    }

    /**
     * Whether the credential stored under $keys is still live: not revoked,
     * so some account still posts with it or is re-derived from it, and a
     * reconnect that keeps none of its targets still refreshes it.
     *
     * @param  array<string, mixed>  $keys
     */
    protected function credentialStillLive(array $keys): bool
    {
        return SocialToken::query()
            ->where($keys + ['purpose' => SocialToken::DEFAULT_PURPOSE])
            ->where('status', '!=', AccountStatus::Revoked->value)
            ->exists();
    }
}
