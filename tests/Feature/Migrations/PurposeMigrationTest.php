<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pr4w\SocialTokens\Actions\StoreLinkedInOrganizations;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Tests\Fixtures\Owner;

/*
 * 3.0 keeps the LinkedIn organizations' member token on its own credential
 * (purpose 'organizations'), apart from the member's personal profile: the
 * migration adds the column, puts it in the unique key, and splits the
 * credentials 2.x shared between the two.
 */

function purposeMigration(): object
{
    return include __DIR__.'/../../../database/migrations/2025_01_01_000008_add_purpose_to_social_tokens.php';
}

/** A 2.x-shaped credential row (tokens encrypted like the model cast does). */
function purposeV2Credential(string $provider, ?string $holder, string $accessToken, ?Owner $owner = null): int
{
    return DB::table('social_tokens')->insertGetId([
        'provider' => $provider,
        'provider_holder_id' => $holder,
        'access_token' => Crypt::encryptString($accessToken),
        'refresh_token' => Crypt::encryptString("{$accessToken}-refresh"),
        'scopes' => json_encode(['w_organization_social']),
        'status' => 'active',
        'expires_at' => now()->addDays(60),
        'renew_at' => now()->addDays(55),
        'ownable_type' => $owner?->getMorphClass(),
        'ownable_id' => $owner?->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function purposeV2Account(string $provider, string $userId, ?string $holder, int $tokenId, ?Owner $owner = null): int
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

/** Unique indexes of social_tokens, as name => columns. */
function purposeUniqueIndexes(): array
{
    return collect(Schema::getIndexes('social_tokens'))
        ->filter(fn (array $index) => $index['unique'] && ! $index['primary'])
        ->mapWithKeys(fn (array $index) => [$index['name'] => $index['columns']])
        ->all();
}

beforeEach(function () {
    // Back to the 2.x schema (the TestCase ran every migration), to seed 2.x data.
    purposeMigration()->down();

    // The split is for apps whose profile and organizations come from two
    // authorizations (single authorization mode, the default, keeps them).
    config(['social-tokens.connectors.linkedin.single_authorization' => false]);

    $this->owner = Owner::create(['name' => 'alice']);
});

it('swaps the owner-scoped unique key for one with the purpose', function () {
    expect(Schema::hasColumn('social_tokens', 'purpose'))->toBeFalse()
        ->and(purposeUniqueIndexes())->toBe(['social_tokens_owner_identity_unique' => ['provider', 'provider_holder_id', 'ownable_type', 'ownable_id']]);

    purposeMigration()->up();

    expect(purposeUniqueIndexes())->toBe(['social_tokens_identity_purpose_unique' => ['provider', 'provider_holder_id', 'purpose', 'ownable_type', 'ownable_id']])
        ->and(DB::table('social_tokens')->insertGetId(['provider' => 'x', 'provider_holder_id' => 'h', 'created_at' => now(), 'updated_at' => now()]))->toBeInt()
        ->and(DB::table('social_tokens')->where('provider', 'x')->value('purpose'))->toBe('');
});

it('moves the organizations of a credential shared with the personal profile to a copy', function () {
    $shared = purposeV2Credential('linkedin', 'member-1', 'member-token', $this->owner);
    $personal = purposeV2Account('linkedin', 'member-1', 'member-1', $shared, $this->owner);
    $acme = purposeV2Account('linkedin', '111', 'member-1', $shared, $this->owner);
    $globex = purposeV2Account('linkedin', '222', 'member-1', $shared, $this->owner);

    purposeMigration()->up();

    $personalCredential = SocialAccount::find($personal)->credential;
    $organizations = SocialAccount::find($acme)->credential;

    expect($personalCredential->getKey())->toBe($shared)
        ->and($personalCredential->purpose)->toBe('')
        ->and($organizations->getKey())->not->toBe($shared)
        ->and($organizations->purpose)->toBe(StoreLinkedInOrganizations::PURPOSE)
        ->and(SocialAccount::find($globex)->social_token_id)->toBe($organizations->getKey())
        // Copied as stored: still decryptable, same grant, same schedule, same owner.
        ->and($organizations->access_token)->toBe('member-token')
        ->and($organizations->refresh_token)->toBe('member-token-refresh')
        ->and($organizations->scopes)->toBe(['w_organization_social'])
        ->and($organizations->renew_at->equalTo($personalCredential->renew_at))->toBeTrue()
        ->and($organizations->ownable->is($this->owner))->toBeTrue()
        ->and(SocialToken::count())->toBe(2);
});

it('gives a credential backing organizations only the purpose in place', function () {
    $token = purposeV2Credential('linkedin', 'member-1', 'member-token', $this->owner);
    $org = purposeV2Account('linkedin', '111', 'member-1', $token, $this->owner);

    purposeMigration()->up();

    expect(SocialToken::count())->toBe(1)
        ->and(SocialToken::find($token)->purpose)->toBe(StoreLinkedInOrganizations::PURPOSE)
        ->and(SocialAccount::find($org)->social_token_id)->toBe($token);
});

it('keeps the credential the profile and the organizations share in single authorization mode', function (mixed $setting) {
    config(['social-tokens.connectors.linkedin.single_authorization' => $setting]);
    $shared = purposeV2Credential('linkedin', 'member-1', 'member-token', $this->owner);
    $personal = purposeV2Account('linkedin', 'member-1', 'member-1', $shared, $this->owner);
    $acme = purposeV2Account('linkedin', '111', 'member-1', $shared, $this->owner);

    purposeMigration()->up();

    expect(SocialToken::count())->toBe(1)
        ->and(SocialToken::find($shared)->purpose)->toBe('')
        ->and(SocialAccount::find($personal)->social_token_id)->toBe($shared)
        ->and(SocialAccount::find($acme)->social_token_id)->toBe($shared);
})->with(['on' => true, 'the string "true"' => 'true', 'missing from a published config' => null]);

it('leaves every other credential the default one', function () {
    $personal = purposeV2Credential('linkedin', 'member-1', 'member-token');
    purposeV2Account('linkedin', 'member-1', 'member-1', $personal);
    $unused = purposeV2Credential('linkedin', 'member-2', 'unused-token');
    $meta = purposeV2Credential('facebook', 'fb-1', 'meta-user-token');
    purposeV2Account('instagram', 'ig-1', 'fb-1', $meta);

    purposeMigration()->up();

    expect(SocialToken::count())->toBe(3)
        ->and(SocialToken::whereKey([$personal, $unused, $meta])->pluck('purpose')->unique()->all())->toBe(['']);
});

it('splits the credentials of each owner apart', function () {
    $bob = Owner::create(['name' => 'bob']);

    foreach ([$this->owner, $bob] as $owner) {
        $token = purposeV2Credential('linkedin', 'member-1', "token-{$owner->name}", $owner);
        purposeV2Account('linkedin', 'member-1', 'member-1', $token, $owner);
        purposeV2Account('linkedin', '111', 'member-1', $token, $owner);
    }

    purposeMigration()->up();

    foreach ([$this->owner, $bob] as $owner) {
        $org = SocialAccount::ownedBy($owner)->where('provider_user_id', '111')->sole();

        expect($org->credential->purpose)->toBe(StoreLinkedInOrganizations::PURPOSE)
            ->and($org->credential->access_token)->toBe("token-{$owner->name}")
            ->and($org->credential->ownable->is($owner))->toBeTrue();
    }

    expect(SocialToken::count())->toBe(4);
});

it('changes nothing when run again', function () {
    $shared = purposeV2Credential('linkedin', 'member-1', 'member-token', $this->owner);
    purposeV2Account('linkedin', 'member-1', 'member-1', $shared, $this->owner);
    purposeV2Account('linkedin', '111', 'member-1', $shared, $this->owner);

    purposeMigration()->up();
    $before = DB::table('social_tokens')->orderBy('id')->get();

    purposeMigration()->up();

    expect(DB::table('social_tokens')->orderBy('id')->get())->toEqual($before)
        ->and(purposeUniqueIndexes())->toHaveCount(1);
});

it('completes when run again after failing partway through the split', function () {
    $shared = purposeV2Credential('linkedin', 'member-1', 'member-token', $this->owner);
    $personal = purposeV2Account('linkedin', 'member-1', 'member-1', $shared, $this->owner);
    $org = purposeV2Account('linkedin', '111', 'member-1', $shared, $this->owner);

    // The connection drops while the organizations are being moved.
    $armed = true;
    DB::connection()->beforeExecuting(function (string $query) use (&$armed) {
        if ($armed && str_starts_with($query, 'update "social_accounts"')) {
            $armed = false;

            throw new RuntimeException('Lost connection');
        }
    });

    expect(fn () => purposeMigration()->up())->toThrow(RuntimeException::class, 'Lost connection')
        ->and(SocialToken::count())->toBe(1); // the copy went with the transaction

    purposeMigration()->up();

    expect(SocialAccount::find($org)->credential->purpose)->toBe(StoreLinkedInOrganizations::PURPOSE)
        ->and(SocialAccount::find($personal)->social_token_id)->toBe($shared)
        ->and(SocialToken::count())->toBe(2);
});

it('splits what 2025_01_01_000007 hands over on an upgrade from 1.x', function () {
    // 1.x: one credential for the member, an owner-less personal row and an owned organization.
    purposeMigration()->down();
    $owner = include __DIR__.'/../../../database/migrations/2025_01_01_000007_scope_identity_to_owner.php';
    $owner->down();

    $member = DB::table('social_tokens')->insertGetId([
        'provider' => 'linkedin', 'provider_holder_id' => 'member-1', 'access_token' => Crypt::encryptString('member-token'),
        'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $personal = purposeV2Account('linkedin', 'member-1', 'member-1', $member, $this->owner);
    $org = purposeV2Account('linkedin', '111', 'member-1', $member, $this->owner);

    $owner->up();
    purposeMigration()->up();

    expect(SocialAccount::find($personal)->credential->purpose)->toBe('')
        ->and(SocialAccount::find($org)->credential->purpose)->toBe(StoreLinkedInOrganizations::PURPOSE)
        ->and(SocialAccount::find($org)->credential->ownable->is($this->owner))->toBeTrue()
        ->and(SocialAccount::find($org)->credential->access_token)->toBe('member-token');
});

it('refuses to roll back while a holder has several credentials for one owner', function () {
    $shared = purposeV2Credential('linkedin', 'member-1', 'member-token', $this->owner);
    purposeV2Account('linkedin', 'member-1', 'member-1', $shared, $this->owner);
    purposeV2Account('linkedin', '111', 'member-1', $shared, $this->owner);
    purposeMigration()->up();

    expect(fn () => purposeMigration()->down())->toThrow(RuntimeException::class, 'Cannot roll back')
        ->and(Schema::hasColumn('social_tokens', 'purpose'))->toBeTrue();
});

it('rolls back to the 2.x key when every holder has one credential per owner', function () {
    purposeMigration()->up();
    purposeV2Credential('tiktok', 'tt-1', 'tt-token', $this->owner);

    purposeMigration()->down();

    expect(Schema::hasColumn('social_tokens', 'purpose'))->toBeFalse()
        ->and(purposeUniqueIndexes())->toBe(['social_tokens_owner_identity_unique' => ['provider', 'provider_holder_id', 'ownable_type', 'ownable_id']])
        ->and(fn () => purposeV2Credential('tiktok', 'tt-1', 'tt-token-2', $this->owner))->toThrow(QueryException::class);

    purposeMigration()->up(); // leave the schema as the TestCase expects
});

it('refuses before changing anything when the key would not fit InnoDB', function () {
    $column = fn (string $type) => ['type' => $type, 'collation' => str_contains($type, 'char') ? 'utf8mb4_unicode_ci' : null];

    $check = fn (string $provider) => (fn () => $this->ensureKeyColumnsFit('social_tokens', [
        'provider' => $column($provider),
        'provider_holder_id' => $column('varchar(255)'),
        'purpose' => $column('varchar(32)'),
        'ownable_type' => $column('varchar(255)'),
        'ownable_id' => $column('char(36)'),
    ]))->call(purposeMigration());

    $check('varchar(50)'); // 2512 bytes: fits

    expect(fn () => $check('varchar(255)'))->toThrow(RuntimeException::class, '3332 bytes');
});

it('keeps the key name within 63 characters for a long table name', function () {
    $migration = purposeMigration();
    $table = str_repeat('very_long_', 6).'social_tokens';

    $name = (fn () => $this->indexName($table, 'identity_purpose_unique'))->call($migration);
    $other = (fn () => $this->indexName($table, 'owner_identity_unique'))->call($migration);

    expect(strlen($name))->toBeLessThanOrEqual(63)
        ->and($name)->not->toBe($other)
        ->and((fn () => $this->indexName('social_tokens', 'identity_purpose_unique'))->call($migration))
        ->toBe('social_tokens_identity_purpose_unique');
});
