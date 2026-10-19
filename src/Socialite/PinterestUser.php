<?php

namespace Pr4w\SocialTokens\Socialite;

use Laravel\Socialite\Two\User;

/**
 * A Pinterest connection: getId() is the account's id (stable, unlike its
 * username), getNickname() its username, getName() its business name or
 * username, getRaw() the GET /user_account answer (account_type PINNER or
 * BUSINESS, follower_count...). accessTokenResponseBody is the token answer,
 * as SocialiteProviders' users carry it: StoreConnection reads the refresh
 * token's lifetime there.
 */
class PinterestUser extends User
{
    /** @var array<string, mixed> */
    public array $accessTokenResponseBody = [];
}
