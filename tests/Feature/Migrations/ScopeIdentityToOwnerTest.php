<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Tests\Fixtures\Owner;

/*
 * The 2.0 migration that scopes identity to the owner: it gives every credential
 * the owner of the accounts it backs, splitting a credential shared by several
 * owners into one per owner, then swaps the unique indexes.
 */

function ownerMigration(): object
{
    return include __DIR__.'/../../../database/migrations/2025_01_01_000007_scope_identity_to_owner.php';
}

/** Insert a v1-shaped credential row (tokens encrypted like the model cast does). */
function v1Credential(string $provider, string $holder, string $accessToken): int
{
    return DB::table('social_tokens')->insertGetId([
        'provider' => $provider,
        'provider_holder_id' => $holder,
        'access_token' => Crypt::encryptString($accessToken),
        'status' => 'active',
        'expires_at' => now()->addDays(60),
        'renew_at' => now()->addDays(53),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function v1Account(string $provider, string $userId, ?string $holder, ?int $tokenId, ?Owner $owner): int
{
    return DB::table('social_accounts')->insertGetId([
        'provider' => $provider,
        'provider_user_id' => $userId,
        'provider_holder_id' => $holder,
        'social_token_id' => $tokenId,
        'ownable_type' => $owner?->getMorphClass(),
        'ownable_id' => $owner?->getKey(),
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

beforeEach(function () {
    // Back to the 1.x schema (the TestCase ran every migration), to seed v1 data.
    ownerMigration()->down();

    $this->alice = Owner::create(['name' => 'alice']);
    $this->bob = Owner::create(['name' => 'bob']);
});

it('splits a credential shared by two owners into one per owner', function () {
    $shared = v1Credential('facebook', 'fb-1', 'meta-user-token');
    $aliceIg = v1Account('instagram', 'ig-1', 'fb-1', $shared, $this->alice);
    $bobIg = v1Account('instagram', 'ig-2', 'fb-1', $shared, $this->bob);

    ownerMigration()->up();

    $aliceToken = SocialAccount::find($aliceIg)->credential;
    $bobToken = SocialAccount::find($bobIg)->credential;

    expect($aliceToken->is($bobToken))->toBeFalse()
        ->and($aliceToken->ownable->is($this->alice))->toBeTrue()
        ->and($bobToken->ownable->is($this->bob))->toBeTrue()
        ->and($aliceToken->access_token)->toBe('meta-user-token')
        ->and($bobToken->access_token)->toBe('meta-user-token') // the copy is still decryptable
        ->and($bobToken->provider_holder_id)->toBe('fb-1')
        ->and($bobToken->renew_at->equalTo($aliceToken->renew_at))->toBeTrue()
        ->and(SocialToken::where('provider_holder_id', 'fb-1')->count())->toBe(2);
});

it('gives a single-owner credential its owner without copying it', function () {
    $token = v1Credential('tiktok', 'tt-1', 'tt-access');
    v1Account('tiktok', 'tt-1', 'tt-1', $token, $this->alice);

    ownerMigration()->up();

    expect(SocialToken::count())->toBe(1)
        ->and(SocialToken::find($token)->ownable->is($this->alice))->toBeTrue();
});

it('owns a Meta user credential through the Pages that name its holder', function () {
    // Facebook Pages post with their own page tokens and point at the Meta user
    // credential only through provider_holder_id.
    $user = v1Credential('facebook', 'fb-1', 'meta-user-token');
    $pageToken = v1Credential('facebook', 'page-1', 'page-token');
    v1Account('facebook', 'page-1', 'fb-1', $pageToken, $this->alice);

    ownerMigration()->up();

    expect(SocialToken::find($user)->ownable?->is($this->alice))->toBeTrue()
        ->and(SocialToken::find($pageToken)->ownable?->is($this->alice))->toBeTrue();
});

it('keeps owner-less rows owner-less, and splits them from owned ones', function () {
    $orphan = v1Credential('tiktok', 'tt-orphan', 'orphan-access');
    $mixed = v1Credential('linkedin', 'member-1', 'member-token');
    $ownerless = v1Account('linkedin', 'member-1', 'member-1', $mixed, null);
    $owned = v1Account('linkedin', '42', 'member-1', $mixed, $this->alice);

    ownerMigration()->up();

    expect(SocialToken::find($orphan)->ownable_type)->toBeNull()
        ->and(SocialAccount::find($ownerless)->credential->ownable_type)->toBeNull()
        ->and(SocialAccount::find($owned)->credential->ownable->is($this->alice))->toBeTrue()
        ->and(SocialAccount::find($ownerless)->social_token_id)->not->toBe(SocialAccount::find($owned)->social_token_id);
});

it('refuses to roll back once two owners hold the same external account', function () {
    ownerMigration()->up();

    v1Account('tiktok', 'tt-1', 'tt-1', null, $this->alice);
    v1Account('tiktok', 'tt-1', 'tt-1', null, $this->bob);

    ownerMigration()->down();
})->throws(RuntimeException::class, 'Cannot roll back');

it('rolls back to the 1.x schema when no owner shares an account', function () {
    ownerMigration()->up();
    v1Account('tiktok', 'tt-1', 'tt-1', null, $this->alice);

    ownerMigration()->down();

    // The global uniqueness is back.
    expect(fn () => v1Account('tiktok', 'tt-1', 'tt-1', null, $this->bob))->toThrow(QueryException::class);

    ownerMigration()->up(); // leave the schema as the TestCase expects
});
