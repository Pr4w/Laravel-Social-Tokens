<?php

namespace Pr4w\SocialTokens\Tests\Fixtures;

use Laravel\Socialite\Two\User;

/**
 * The user SocialiteProviders' drivers (TikTok, Threads) return: core
 * Socialite's, plus the raw token response. Core Socialite's own drivers
 * (LinkedIn, Google, X) keep no token response.
 */
class SocialiteProvidersUser extends User
{
    /** @var array<string, mixed> */
    public array $accessTokenResponseBody = [];
}
