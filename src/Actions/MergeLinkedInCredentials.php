<?php

namespace Pr4w\SocialTokens\Actions;

use Illuminate\Support\Facades\DB;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;

/**
 * Single authorization mode (connectors.linkedin.single_authorization): the
 * member's organizations post with the member's default credential, like
 * their personal profile. This folds a separate organizations credential
 * (purpose StoreLinkedInOrganizations::PURPOSE, from before the mode was on,
 * or split by the 2025_01_01_000008 migration) into it, so one token is
 * renewed once rather than two copies with the same refresh token.
 *
 * The token state kept is the fresher of the two (see fresher()), copied as
 * stored: encrypted columns are never decrypted. The organizations move to
 * the default credential, and the separate one is deleted: nothing uses it
 * any more. Without a default credential, the separate one simply becomes it.
 */
class MergeLinkedInCredentials
{
    /** What a credential holds about its token, copied when the separate one is the fresher. */
    protected const TOKEN_STATE = [
        'access_token', 'refresh_token', 'expires_at', 'refresh_expires_at', 'renew_at',
        'last_renewed_at', 'data_access_expires_at', 'data_access_warned_at', 'scopes',
        'last_error', 'failed_checks', 'status',
    ];

    /**
     * @return SocialToken The credential the organizations now post with: the member's default one.
     */
    public function handle(SocialToken $organizations): SocialToken
    {
        return DB::transaction(function () use ($organizations) {
            $separate = SocialToken::query()->whereKey($organizations->getKey())->lockForUpdate()->first();

            if ($separate === null || $separate->purpose !== StoreLinkedInOrganizations::PURPOSE) {
                return $separate ?? $organizations; // gone, or merged by a concurrent call
            }

            $default = SocialToken::query()
                ->where('provider', $separate->provider)
                ->where('provider_holder_id', $separate->provider_holder_id)
                ->forPurpose()
                ->where('ownable_type', $separate->ownable_type)
                ->where('ownable_id', $separate->ownable_id)
                ->lockForUpdate()
                ->first();

            if ($default === null) {
                $separate->forceFill(['purpose' => SocialToken::DEFAULT_PURPOSE])->save();

                return $separate;
            }

            if (static::fresher($separate, $default)) {
                // Raw values through the query builder: the ciphertext is
                // copied as is, not decrypted and encrypted again.
                $state = array_intersect_key($separate->getAttributes(), array_flip(static::TOKEN_STATE));
                SocialToken::query()->whereKey($default->getKey())->update($state);
            }

            SocialAccount::query()
                ->where('social_token_id', $separate->getKey())
                ->update(['social_token_id' => $default->getKey()]);

            SocialToken::query()->whereKey($separate->getKey())->delete();

            return $default->refresh();
        });
    }

    /**
     * Whether $a holds a better token than $b: a usable one first (active,
     * then needs_reconnect, then revoked), then the later expiry, then the
     * later write. A tie keeps $b, the default credential.
     */
    protected static function fresher(SocialToken $a, SocialToken $b): bool
    {
        $rank = fn (SocialToken $token) => match ($token->status) {
            AccountStatus::Active => 2,
            AccountStatus::NeedsReconnect => 1,
            default => 0,
        };

        if ($rank($a) !== $rank($b)) {
            return $rank($a) > $rank($b);
        }

        if ($a->expires_at?->getTimestamp() !== $b->expires_at?->getTimestamp()) {
            return ($a->expires_at?->getTimestamp() ?? PHP_INT_MIN) > ($b->expires_at?->getTimestamp() ?? PHP_INT_MIN);
        }

        return ($a->updated_at?->getTimestamp() ?? PHP_INT_MIN) > ($b->updated_at?->getTimestamp() ?? PHP_INT_MIN);
    }
}
