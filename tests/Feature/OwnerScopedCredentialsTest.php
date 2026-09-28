<?php

use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Tests\Fixtures\Owner;

beforeEach(function () {
    $this->alice = Owner::create(['name' => 'alice']);
    $this->bob = Owner::create(['name' => 'bob']);
});

function ownedCredential(string $provider, string $holder, ?Owner $owner, array $attrs = []): SocialToken
{
    $token = new SocialToken(array_merge([
        'provider' => $provider,
        'provider_holder_id' => $holder,
        'access_token' => "{$holder}-access",
        'refresh_token' => "{$holder}-refresh",
        'status' => AccountStatus::Active,
    ], $attrs));

    if ($owner !== null) {
        $token->ownable()->associate($owner);
    }

    $token->save();

    return $token;
}

function ownedAccount(string $provider, string $userId, string $holder, ?int $tokenId, ?Owner $owner): SocialAccount
{
    $account = new SocialAccount([
        'provider' => $provider,
        'provider_user_id' => $userId,
        'provider_holder_id' => $holder,
        'social_token_id' => $tokenId,
        'status' => AccountStatus::Active,
    ]);

    if ($owner !== null) {
        $account->ownable()->associate($owner);
    }

    $account->save();

    return $account;
}

it('scopes accounts and credentials to their owner, or to no owner', function () {
    $aliceToken = ownedCredential('tiktok', 'tt-1', $this->alice);
    $ownerless = ownedCredential('tiktok', 'tt-1', null);
    ownedAccount('tiktok', 'tt-1', 'tt-1', $aliceToken->id, $this->alice);
    ownedAccount('tiktok', 'tt-1', 'tt-1', $ownerless->id, null);

    expect(SocialToken::ownedBy($this->alice)->sole()->is($aliceToken))->toBeTrue()
        ->and(SocialToken::ownedBy(null)->sole()->is($ownerless))->toBeTrue()
        ->and(SocialAccount::ownedBy($this->alice)->sole()->social_token_id)->toBe($aliceToken->id)
        ->and(SocialAccount::ownedBy($this->bob)->count())->toBe(0);
});

it('does not revoke at the provider while another owner still holds a grant for the same user', function () {
    Http::fake();

    $alice = ownedCredential('tiktok', 'tt-1', $this->alice);
    $bob = ownedCredential('tiktok', 'tt-1', $this->bob);

    app(SocialTokens::class)->revoke($bob);

    // Revoking at TikTok/Google can end the whole user's consent for the app,
    // which would kill Alice's grant too: only the local revoke happens.
    Http::assertNothingSent();

    expect($bob->fresh()->status)->toBe(AccountStatus::Revoked)
        ->and($alice->fresh()->status)->toBe(AccountStatus::Active);

    // Once Alice's is gone too, the last revoke reaches the provider.
    app(SocialTokens::class)->revoke($alice);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth/revoke'));
});

it('renews a Meta user credential for the Pages of its own owner only', function () {
    $aliceUser = ownedCredential('facebook', 'fb-1', $this->alice, ['renew_at' => now()->subMinute()]);
    $bobUser = ownedCredential('facebook', 'fb-1', $this->bob, ['renew_at' => now()->subMinute()]);

    // Only Bob still has a Page of that Facebook user (Pages post with their
    // own page tokens and point at the user credential through the holder).
    $page = ownedCredential('facebook', 'page-1', $this->bob);
    ownedAccount('facebook', 'page-1', 'fb-1', $page->id, $this->bob);

    $due = SocialToken::query()->dueForRenewal()->inUse()->pluck('id')->all();

    expect($due)->toBe([$bobUser->id])
        ->and($due)->not->toContain($aliceUser->id);
});
