<?php

namespace Pr4w\SocialTokens\Contracts;

/**
 * Optional connector capability: one connection yields several accounts (a
 * Facebook user's Pages, the Instagram accounts linked to them), stored by an
 * action of its own instead of StoreAccountFromSocialite, which stores one
 * account keyed on the Socialite user's id. StoreConnection hands the
 * connection to that action, every option included; StoreAccountFromSocialite
 * refuses the provider, unless the action is StoreAccountFromSocialite itself
 * (a subclass of a Meta connector that stores one account per connection).
 */
interface FansOutConnections
{
    /**
     * The action that stores this connector's connections, resolved from the
     * container.
     *
     * @return class-string<StoresConnections>
     */
    public function connectionAction(): string;
}
