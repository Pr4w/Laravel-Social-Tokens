<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\AccountRevoked;
use Pr4w\SocialTokens\Events\CredentialRevoked;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Tests\Fixtures\Owner;

/*
 * Nothing tore down what an owner held. When the app deleted a workspace, its
 * accounts stayed active, so its credentials were renewed for good, and each
 * stayed an active grant for its external user: revoke() and disconnect() for
 * any other owner of the same TikTok or Google user saw a "shared grant" and
 * never called the provider, even after every remaining owner disconnected.
 */

beforeEach(function () {
    Http::fake();
    $this->alice = Owner::create(['name' => 'alice']);
    $this->bob = Owner::create(['name' => 'bob']);
});

/** A 1:1 credential and its account, under one owner. */
function forgetOwnerConnect(string $provider, string $holder, ?Owner $owner): SocialAccount
{
    $token = SocialToken::persistCredential(['provider' => $provider, 'provider_holder_id' => $holder] + SocialAccount::ownerKey($owner), [
        'access_token' => "{$holder}-access",
        'refresh_token' => "{$holder}-refresh",
        'expires_at' => now()->addHour(),
        'renew_at' => now()->subMinute(),
    ]);

    return SocialAccount::create([
        'provider' => $provider, 'provider_user_id' => $holder, 'provider_holder_id' => $holder,
        'social_token_id' => $token->getKey(), 'status' => AccountStatus::Active,
    ] + SocialAccount::ownerKey($owner));
}

it('deletes every account and credential of the owner, and nothing of anyone else', function () {
    $alices = forgetOwnerConnect('tiktok', 'tt-1', $this->alice);
    forgetOwnerConnect('google', 'g-1', $this->alice);
    $bobs = forgetOwnerConnect('tiktok', 'tt-1', $this->bob);
    $ownerless = forgetOwnerConnect('tiktok', 'tt-2', null);

    $deleted = app(SocialTokens::class)->forgetOwner($this->alice);

    expect($deleted->pluck('provider_user_id')->all())->toBe(['tt-1', 'g-1'])
        ->and($deleted->every(fn (SocialAccount $account) => $account->status === AccountStatus::Revoked))->toBeTrue()
        ->and(SocialAccount::ownedBy($this->alice)->count())->toBe(0)
        ->and(SocialToken::ownedBy($this->alice)->count())->toBe(0)
        ->and($bobs->fresh()->isUsable())->toBeTrue()
        ->and($bobs->credential->fresh()->access_token)->toBe('tt-1-access')
        ->and($ownerless->fresh()->isUsable())->toBeTrue()
        ->and($alices->exists)->toBeTrue(); // the caller's copy, untouched
});

it('revokes at the provider unless another owner still holds a grant for the same user', function () {
    forgetOwnerConnect('tiktok', 'tt-1', $this->alice);
    forgetOwnerConnect('google', 'g-1', $this->alice);
    $bobs = forgetOwnerConnect('tiktok', 'tt-1', $this->bob);

    app(SocialTokens::class)->forgetOwner($this->alice);

    // Google: Alice was its only owner. TikTok: Bob still holds a grant.
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth2.googleapis.com/revoke') && $request['token'] === 'g-1-refresh');

    // Alice's rows are gone: they no longer keep Bob's disconnect local.
    app(SocialTokens::class)->disconnect($bobs);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'open.tiktokapis.com/v2/oauth/revoke/') && $request['token'] === 'tt-1-access');
});

it('announces the revocations once per row before deleting', function () {
    Event::fake([CredentialRevoked::class, AccountRevoked::class]);
    forgetOwnerConnect('tiktok', 'tt-1', $this->alice);
    $revoked = forgetOwnerConnect('google', 'g-1', $this->alice);
    app(SocialTokens::class)->disconnect($revoked);
    Event::fake([CredentialRevoked::class, AccountRevoked::class]);

    app(SocialTokens::class)->forgetOwner($this->alice);

    Event::assertDispatchedTimes(CredentialRevoked::class, 1);
    Event::assertDispatchedTimes(AccountRevoked::class, 1);
    Http::assertSentCount(2); // the disconnect's Google revoke, then TikTok's: not Google again
});

it('deletes the rows even when a revocation listener throws, then rethrows', function () {
    Event::listen(CredentialRevoked::class, fn () => throw new RuntimeException('listener failed'));
    forgetOwnerConnect('tiktok', 'tt-1', $this->alice);
    forgetOwnerConnect('google', 'g-1', $this->alice);

    expect(fn () => app(SocialTokens::class)->forgetOwner($this->alice))->toThrow(RuntimeException::class, 'listener failed');

    expect(SocialAccount::query()->count())->toBe(0)
        ->and(SocialToken::query()->count())->toBe(0);
    Http::assertSentCount(2);
});

it('deletes the Meta user credential and the Pages behind an owner\'s Facebook connection', function () {
    $user = SocialToken::persistCredential(['provider' => 'facebook', 'provider_holder_id' => 'user-1'] + SocialAccount::ownerKey($this->alice), ['access_token' => 'meta-user']);
    $page = SocialToken::persistCredential(['provider' => 'facebook', 'provider_holder_id' => 'page-1'] + SocialAccount::ownerKey($this->alice), ['access_token' => 'page-token', 'renew_at' => null]);
    SocialAccount::create(['provider' => 'facebook', 'provider_user_id' => 'page-1', 'provider_holder_id' => 'user-1', 'social_token_id' => $page->getKey(), 'status' => AccountStatus::Active] + SocialAccount::ownerKey($this->alice));

    app(SocialTokens::class)->forgetOwner($this->alice);

    expect(DB::table('social_tokens')->count())->toBe(0)
        ->and(DB::table('social_accounts')->count())->toBe(0)
        ->and(SocialToken::query()->find($user->getKey()))->toBeNull();
});

it('does nothing for an owner without rows', function () {
    forgetOwnerConnect('tiktok', 'tt-1', $this->bob);

    expect(app(SocialTokens::class)->forgetOwner($this->alice))->toBeEmpty()
        ->and(SocialAccount::query()->count())->toBe(1);
    Http::assertNothingSent();
});
