<?php

use Laravel\Socialite\Two\User as SocialiteUser;
use Pr4w\SocialTokens\Tests\Fixtures\SocialiteProvidersUser;
use Pr4w\SocialTokens\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * Build a Socialite user with sensible defaults, overridable per test. Given an
 * accessTokenResponseBody, the user of a SocialiteProviders driver; otherwise
 * core Socialite's, which has none.
 */
function socialiteUser(array $attrs = []): SocialiteUser
{
    $user = array_key_exists('accessTokenResponseBody', $attrs) ? new SocialiteProvidersUser : new SocialiteUser;

    // Socialite users are ArrayAccess over this raw array; data_get() touches it.
    $user->setRaw($attrs['raw'] ?? []);

    $user->map([
        'id' => $attrs['id'] ?? 'ext-1',
        'name' => $attrs['name'] ?? 'Test User',
        'nickname' => $attrs['nickname'] ?? 'tester',
        'email' => $attrs['email'] ?? 'test@example.com',
        'avatar' => $attrs['avatar'] ?? 'https://example.com/a.png',
    ]);

    $user->token = $attrs['token'] ?? 'access-token';
    $user->refreshToken = $attrs['refreshToken'] ?? null;
    $user->expiresIn = $attrs['expiresIn'] ?? 3600;
    $user->approvedScopes = $attrs['approvedScopes'] ?? [];

    if ($user instanceof SocialiteProvidersUser) {
        $user->accessTokenResponseBody = $attrs['accessTokenResponseBody'];
    }

    return $user;
}
