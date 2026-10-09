<?php

use Pr4w\SocialTokens\Actions\StoreAccountFromSocialite;
use Pr4w\SocialTokens\Models\SocialToken;

/*
 * LinkedIn's token response lists the granted scopes comma-separated
 * ("email,openid,profile,w_member_social"), and Socialite's LinkedIn drivers
 * split that string on spaces: the approved scopes reach the package as one
 * element holding them all. Stored as is, hasScope('w_member_social') was false
 * and missingScopes() reported every scope.
 */

/** What Socialite's linkedin / linkedin-openid drivers hand over for LinkedIn's `scope`. */
function socialiteLinkedInScopes(string $scope): array
{
    return explode(' ', $scope); // their $scopeSeparator is a space
}

it('splits an element holding several scopes', function (array $scopes, array $expected) {
    expect(SocialToken::normaliseScopes($scopes))->toBe($expected);
})->with([
    'commas' => [['email,openid,profile,w_member_social'], ['email', 'openid', 'profile', 'w_member_social']],
    'spaces' => [['openid https://www.googleapis.com/auth/youtube.upload'], ['openid', 'https://www.googleapis.com/auth/youtube.upload']],
    'mixed, padded and repeated' => [[' a, b ', 'b c', ',,'], ['a', 'b', 'c']],
]);

it('stores the LinkedIn scopes Socialite leaves joined one per element', function () {
    $account = app(StoreAccountFromSocialite::class)->handle('linkedin', socialiteUser([
        'id' => 'member-1',
        'expiresIn' => 60 * 86400,
        'approvedScopes' => socialiteLinkedInScopes('email,openid,profile,w_member_social'),
    ]));

    expect($account->scopes)->toBe(['email', 'openid', 'profile', 'w_member_social'])
        ->and($account->hasScope('w_member_social'))->toBeTrue()
        ->and($account->missingScopes(['openid', 'w_member_social']))->toBe([])
        ->and($account->credential->scopes)->toBe(['email', 'openid', 'profile', 'w_member_social']);
});

it('still treats a scope list with nothing in it as unknown', function () {
    $account = app(StoreAccountFromSocialite::class)->handle('linkedin', socialiteUser([
        'id' => 'member-1',
        'approvedScopes' => socialiteLinkedInScopes(''), // no `scope` in the response
    ]));

    expect($account->scopes)->toBeNull()
        ->and($account->scopesKnown())->toBeFalse();
});
