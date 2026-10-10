<?php

/*
 * 2.0 stopped adopting owner-less rows at connection time and told apps to
 * attach them with $account->ownable()->associate($owner). That saves
 * nothing, moves the account only (its credential stays owner-less, so the
 * owner's next reconnect stores a second credential and orphans the first),
 * and hits the owner-scoped unique key when the owner already reconnected.
 * SocialTokens::adopt() does the whole step.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Actions\StoreConnection;
use Pr4w\SocialTokens\Actions\StoreLinkedInOrganizations;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Tests\Fixtures\Owner;
use Pr4w\SocialTokens\Tests\Fixtures\UpgradeGuide;

const ATTACHED_BY_HAND = '#### Owner-less rows you attached by hand';

beforeEach(fn () => $this->owner = Owner::create(['name' => 'acme']));

function adoptTikTok(array $user = [], ?Owner $owner = null): SocialAccount
{
    return app(StoreConnection::class)->handle('tiktok', socialiteUser($user + ['id' => 'tt-1', 'refreshToken' => 'r-old']), owner: $owner)->sole();
}

it('gives an owner-less account and its credential an owner', function () {
    $account = adoptTikTok();

    $adopted = app(SocialTokens::class)->adopt($account, $this->owner);

    expect($adopted->is($account))->toBeTrue()
        ->and(SocialAccount::ownedBy($this->owner)->sole()->is($account))->toBeTrue()
        ->and(SocialToken::ownedBy($this->owner)->sole()->is($account->credential))->toBeTrue()
        ->and(SocialAccount::ownedBy(null)->count())->toBe(0)
        ->and(SocialToken::ownedBy(null)->count())->toBe(0);
});

it('lets the owner\'s next reconnect update the adopted rows, leaving no credential behind', function () {
    $account = adoptTikTok();
    app(SocialTokens::class)->adopt($account, $this->owner);

    adoptTikTok(['token' => 'new-access', 'refreshToken' => 'r-new'], $this->owner);

    expect(SocialAccount::count())->toBe(1)
        ->and(SocialToken::count())->toBe(1)
        ->and($account->fresh()->credential->refresh_token)->toBe('r-new');
});

it('refuses, changing nothing, when the owner already has that account', function () {
    $ownerless = adoptTikTok();
    adoptTikTok(owner: $this->owner); // reconnected after the upgrade: a row of its own

    expect(fn () => app(SocialTokens::class)->adopt($ownerless, $this->owner))
        ->toThrow(InvalidArgumentException::class, 'already has this account')
        ->and($ownerless->fresh()->ownable_type)->toBeNull()
        ->and(SocialToken::ownedBy(null)->count())->toBe(1);
});

it('refuses an account of another owner', function () {
    $account = adoptTikTok(owner: Owner::create(['name' => 'other']));

    expect(fn () => app(SocialTokens::class)->adopt($account, $this->owner))
        ->toThrow(InvalidArgumentException::class, 'belongs to another owner');
});

it('finishes an adoption made by associating the account alone, as the 2.0 notes advised', function () {
    $account = adoptTikTok();
    $account->ownable()->associate($this->owner)->save();

    expect(SocialToken::ownedBy($this->owner)->count())->toBe(0);

    app(SocialTokens::class)->adopt($account, $this->owner);

    expect(SocialToken::ownedBy($this->owner)->sole()->is($account->credential))->toBeTrue();
});

it('moves a shared credential with the last owner-less account that uses it', function () {
    $credential = SocialToken::factory()->forProvider('facebook', 'fb-1')->withoutRefreshToken()->create();
    [$first, $second] = SocialAccount::factory()->count(2)->forProvider('instagram')->heldBy('fb-1')
        ->for($credential, 'credential')->create()->all();

    app(SocialTokens::class)->adopt($first, $this->owner);

    // The second account, still owner-less, posts with it.
    expect($credential->fresh()->ownable_type)->toBeNull();

    app(SocialTokens::class)->adopt($second, $this->owner);

    expect(SocialToken::ownedBy($this->owner)->sole()->is($credential))->toBeTrue()
        ->and(SocialAccount::ownedBy($this->owner)->count())->toBe(2);
});

it('moves the Meta user credential behind an adopted Facebook Page, which keeps it in use', function () {
    $user = SocialToken::factory()->forProvider('facebook', 'fb-1')->withoutRefreshToken()->create();
    $page = SocialAccount::factory()->forProvider('facebook')->heldBy('fb-1')->create();

    app(SocialTokens::class)->adopt($page, $this->owner);

    expect(SocialToken::ownedBy($this->owner)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$user->getKey(), $page->social_token_id])->sort()->values()->all())
        ->and(SocialToken::inUse()->whereKey($user->getKey())->exists())->toBeTrue();
});

it('keeps the account on its owner-less credential when the owner already holds one for that user', function () {
    Http::preventStrayRequests();
    $ownerless = SocialAccount::factory()->forProvider('instagram')->heldBy('fb-1')->create();
    SocialToken::factory()->forProvider('facebook', 'fb-1')->ownedBy($this->owner)->create();

    app(SocialTokens::class)->adopt($ownerless, $this->owner);

    expect($ownerless->fresh()->ownable_id)->toBe($this->owner->getKey())
        ->and($ownerless->fresh()->credential->ownable_type)->toBeNull()
        ->and(SocialToken::ownedBy($this->owner)->count())->toBe(1);
});

it('finishes every account attached by hand with the UPGRADE fix-up', function () {
    $tiktok = adoptTikTok();
    $tiktok->ownable()->associate($this->owner)->save();
    $page = SocialAccount::factory()->forProvider('facebook')->heldBy('fb-1')->create();
    $user = SocialToken::factory()->forProvider('facebook', 'fb-1')->create();
    $page->ownable()->associate($this->owner)->save();
    $untouched = SocialAccount::factory()->create(); // owner-less, and left so

    UpgradeGuide::run(ATTACHED_BY_HAND, 'adopt(');

    expect(SocialToken::ownedBy($this->owner)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$tiktok->social_token_id, $page->social_token_id, $user->getKey()])->sort()->values()->all())
        ->and($untouched->fresh()->credential->ownable_type)->toBeNull();
});

it('renews the Meta user token behind a Page attached by hand, which the first 3.0 dispatcher run unscheduled', function () {
    // 2.x data: the Page attached to its owner by hand, the Meta user token
    // behind it still owner-less, so no account of its owner keeps it in
    // use. The dispatcher runs every 15 minutes from the deploy on, before
    // anyone runs the fix-up, and unschedules it.
    $page = SocialAccount::factory()->forProvider('facebook')->heldBy('fb-1')->create();
    $user = SocialToken::factory()->forProvider('facebook', 'fb-1')->withoutRefreshToken()->create(['expires_at' => now()->addDays(5)]);
    $page->ownable()->associate($this->owner)->save();

    $this->artisan('social-tokens:dispatch-renewals')->expectsOutputToContain('Unscheduled 1 credential(s)')->assertSuccessful();

    expect($user->fresh()->renew_at)->toBeNull();

    UpgradeGuide::run(ATTACHED_BY_HAND, 'adopt(');

    // Due again, as its expiry says: the next run renews it, or flags it.
    expect($user->fresh()->ownable_id)->toBe($this->owner->getKey())
        ->and(SocialToken::query()->dueForRenewal()->inUse()->whereKey($user->getKey())->exists())->toBeTrue()
        ->and($user->fresh()->renew_at->equalTo($user->expires_at->copy()->subDays(7)))->toBeTrue();
});

it('leaves unscheduled a credential it adopts that has nothing to renew', function () {
    // A Page token, and a Meta user token that never expires: static.
    $page = SocialAccount::factory()->forProvider('facebook')->heldBy('fb-1')
        ->credential(fn ($credential) => $credential->neverExpiring())->create();
    $user = SocialToken::factory()->forProvider('facebook', 'fb-1')->neverExpiring()->create();

    app(SocialTokens::class)->adopt($page, $this->owner);

    expect(SocialToken::ownedBy($this->owner)->whereNotNull('renew_at')->count())->toBe(0)
        ->and($user->fresh()->ownable_id)->toBe($this->owner->getKey());
});

it('finishes every account attached by hand, past the first thousand', function () {
    // each() pages by offset: an account adopted in one page leaves the
    // query, and the next offset skipped as many still half-adopted ones.
    $now = now();

    foreach (array_chunk(range(1, 1050), 100) as $chunk) {
        DB::table((new SocialToken)->getTable())->insert(array_map(fn (int $i) => [
            'provider' => 'tiktok', 'provider_holder_id' => "tt-{$i}", 'purpose' => SocialToken::DEFAULT_PURPOSE,
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ], $chunk));
    }

    $credentials = SocialToken::query()->pluck('id', 'provider_holder_id');

    foreach (array_chunk(range(1, 1050), 100) as $chunk) {
        DB::table((new SocialAccount)->getTable())->insert(array_map(fn (int $i) => [
            'provider' => 'tiktok', 'provider_user_id' => "tt-{$i}", 'provider_holder_id' => "tt-{$i}",
            'ownable_type' => $this->owner->getMorphClass(), 'ownable_id' => $this->owner->getKey(),
            'social_token_id' => $credentials["tt-{$i}"], 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ], $chunk));
    }

    UpgradeGuide::run(ATTACHED_BY_HAND, 'adopt(');

    expect(SocialToken::ownedBy(null)->count())->toBe(0)
        ->and(SocialToken::ownedBy($this->owner)->count())->toBe(1050);
});

/**
 * A LinkedIn member's profile, on the personal credential, and one of the
 * organizations they administer, on the organizations credential, both
 * owner-less.
 *
 * @return array{SocialAccount, SocialAccount}
 */
function ownerlessLinkedInMember(): array
{
    return [
        SocialAccount::factory()->forProvider('linkedin')->create(['provider_user_id' => 'm-1']),
        SocialAccount::factory()->linkedInOrganization('m-1')->create(),
    ];
}

it('moves a LinkedIn profile\'s credential although the member\'s organizations are still owner-less', function () {
    // An organization posts with the organizations credential, not with the
    // member's personal one: it does not keep that one owner-less.
    [$profile, $organization] = ownerlessLinkedInMember();

    app(SocialTokens::class)->adopt($profile, $this->owner);

    expect($profile->credential->fresh()->ownable_id)->toBe($this->owner->getKey())
        ->and($organization->credential->fresh()->ownable_type)->toBeNull();
});

it('gives a LinkedIn member\'s profile and organizations to the owners that adopt them', function () {
    [$profile, $organization] = ownerlessLinkedInMember();
    $other = Owner::create(['name' => 'other']);

    app(SocialTokens::class)->adopt($organization, $this->owner);
    app(SocialTokens::class)->adopt($profile, $other);

    expect($organization->credential->fresh()->ownable_id)->toBe($this->owner->getKey())
        ->and($profile->credential->fresh()->ownable_id)->toBe($other->getKey())
        ->and(SocialToken::ownedBy(null)->count())->toBe(0);
});

it('gives a LinkedIn member\'s credentials to the owner that adopts their profile and organizations, in either order', function (bool $profileFirst) {
    [$profile, $organization] = ownerlessLinkedInMember();

    foreach ($profileFirst ? [$profile, $organization] : [$organization, $profile] as $account) {
        app(SocialTokens::class)->adopt($account, $this->owner);
    }

    expect(SocialToken::ownedBy($this->owner)->pluck('purpose')->sort()->values()->all())
        ->toBe([SocialToken::DEFAULT_PURPOSE, StoreLinkedInOrganizations::PURPOSE])
        ->and(SocialToken::ownedBy(null)->count())->toBe(0);
})->with(['profile first' => true, 'organizations first' => false]);

it('keeps the organizations credential owner-less while another organization of the member is', function () {
    [, $organization] = ownerlessLinkedInMember();
    SocialAccount::factory()->linkedInOrganization('m-1')->for($organization->credential, 'credential')->create();

    app(SocialTokens::class)->adopt($organization, $this->owner);

    expect($organization->credential->fresh()->ownable_type)->toBeNull();
});
