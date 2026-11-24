<?php

namespace Pr4w\SocialTokens\Contracts;

use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\RenewalResult;

/**
 * Optional connector capability, for a credential several accounts post with
 * (a fan-out): list the accounts its holder can still post as. A LinkedIn
 * member who is no longer an organization's admin, or a Facebook user who no
 * longer manages the Page behind an Instagram account, keeps a working token:
 * the renewal and the token checks see nothing, while posting as that account
 * fails. A connection reconciles them; check-static does it every day in
 * between (`reconcile_accounts`), flagging an account missing from the list,
 * as a connection would, once a second read confirms it.
 */
interface ListsReachableAccounts
{
    /**
     * The account providers the credential fans out to. Its accounts of these
     * providers are checked against reachableAccountIds(), except its holder's
     * own (provider_user_id = provider_holder_id: a LinkedIn member's profile).
     *
     * @return array<int, string>
     */
    public function reachableAccountProviders(): array;

    /**
     * The ids (provider_user_id) of the accounts the credential's holder can
     * still post as, read whole with its stored token: the list a connection
     * reconciles against. A failure when it cannot be read whole; nothing is
     * decided then. Must not write to the database.
     *
     * @return array<int, string>|RenewalResult
     */
    public function reachableAccountIds(SocialToken $credential): array|RenewalResult;

    /** Why an account missing from the list is flagged: the reason a connection gives. */
    public function unreachableAccountReason(): string;
}
