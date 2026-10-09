<?php

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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
function v1Credential(string $provider, ?string $holder, string $accessToken): int
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

it('copies a credential without a holder instead of reusing another one', function () {
    // The old unique key let any number of holder-less credentials through, so
    // another one of the same provider and owner is not a copy of this one.
    $bobsOwn = v1Credential('custom', null, 'bob-grant');
    $shared = v1Credential('custom', null, 'shared-grant');
    v1Account('custom', 'c-1', null, $bobsOwn, $this->bob);
    $alice = v1Account('custom', 'c-2', null, $shared, $this->alice);
    $bob = v1Account('custom', 'c-3', null, $shared, $this->bob);

    ownerMigration()->up();

    $bobToken = SocialAccount::find($bob)->credential;

    expect(SocialToken::count())->toBe(3)
        ->and($bobToken->access_token)->toBe('shared-grant')
        ->and($bobToken->ownable->is($this->bob))->toBeTrue()
        ->and($bobToken->getKey())->not->toBe($bobsOwn)
        ->and(SocialAccount::find($alice)->credential->access_token)->toBe('shared-grant');
});

it('gives a credential without a holder no owner through accounts without one', function () {
    // An account without a holder names no credential: nothing ties this one to alice.
    $orphan = v1Credential('custom', null, 'orphan-grant');
    v1Account('custom', 'c-1', null, v1Credential('custom', null, 'alice-grant'), $this->alice);

    ownerMigration()->up();

    expect(SocialToken::find($orphan)->ownable_type)->toBeNull()
        ->and(SocialToken::count())->toBe(2);
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

/** Unique indexes of a table, as name => columns. */
function uniqueIndexes(string $table): array
{
    return collect(Schema::getIndexes($table))
        ->filter(fn (array $index) => $index['unique'] && ! $index['primary'])
        ->mapWithKeys(fn (array $index) => [$index['name'] => $index['columns']])
        ->all();
}

it('runs outside one long transaction', function () {
    // On PostgreSQL one transaction would hold the ALTER's ACCESS EXCLUSIVE
    // lock on social_tokens for the whole backfill.
    expect(ownerMigration()->withinTransaction)->toBeFalse();
});

it('commits the backfill in short chunks', function () {
    foreach (range(1, 250) as $i) {
        v1Credential('tiktok', "tt-{$i}", "access-{$i}");
    }

    $transactions = 0;
    Event::listen(TransactionBeginning::class, function () use (&$transactions) {
        $transactions++;
    });

    ownerMigration()->up();

    expect($transactions)->toBe(3);
});

it('completes when run again after failing partway through the backfill', function () {
    $shared = v1Credential('facebook', 'fb-1', 'meta-user-token');
    $aliceIg = v1Account('instagram', 'ig-1', 'fb-1', $shared, $this->alice);
    $bobIg = v1Account('instagram', 'ig-2', 'fb-1', $shared, $this->bob);

    // The connection drops while the shared credential is being copied.
    $armed = true;
    DB::connection()->beforeExecuting(function (string $query) use (&$armed) {
        if ($armed && str_starts_with($query, 'insert into "social_tokens"')) {
            $armed = false;

            throw new RuntimeException('Lost connection');
        }
    });

    expect(fn () => ownerMigration()->up())->toThrow(RuntimeException::class, 'Lost connection');

    ownerMigration()->up();

    $aliceToken = SocialAccount::find($aliceIg)->credential;
    $bobToken = SocialAccount::find($bobIg)->credential;

    expect($aliceToken->ownable->is($this->alice))->toBeTrue()
        ->and($bobToken->ownable->is($this->bob))->toBeTrue()
        ->and($bobToken->access_token)->toBe('meta-user-token')
        ->and(SocialToken::where('provider_holder_id', 'fb-1')->count())->toBe(2)
        ->and(uniqueIndexes('social_tokens'))->toBe(['social_tokens_owner_identity_unique' => ['provider', 'provider_holder_id', 'ownable_type', 'ownable_id']])
        ->and(uniqueIndexes('social_accounts'))->toBe(['social_accounts_owner_identity_unique' => ['provider', 'provider_user_id', 'ownable_type', 'ownable_id']]);
});

it('changes nothing when run again on a migrated schema', function () {
    // An owner-less Page keeps the Meta user credential; the owned Pages get copies.
    $user = v1Credential('facebook', 'fb-1', 'meta-user-token');
    foreach ([[null, 'page-1'], [$this->alice, 'page-2'], [$this->bob, 'page-3']] as [$owner, $page]) {
        v1Account('facebook', $page, 'fb-1', v1Credential('facebook', $page, "{$page}-token"), $owner);
    }
    $member = v1Credential('linkedin', 'member-1', 'member-token');
    v1Account('linkedin', 'member-1', 'member-1', $member, null);
    v1Account('linkedin', '42', 'member-1', $member, $this->alice);

    ownerMigration()->up();
    $before = DB::table('social_tokens')->orderBy('id')->get(['id', 'provider', 'provider_holder_id', 'ownable_type', 'ownable_id']);
    $accounts = DB::table('social_accounts')->orderBy('id')->pluck('social_token_id', 'id');

    ownerMigration()->up();

    expect(DB::table('social_tokens')->orderBy('id')->get(['id', 'provider', 'provider_holder_id', 'ownable_type', 'ownable_id']))->toEqual($before)
        ->and(DB::table('social_accounts')->orderBy('id')->pluck('social_token_id', 'id'))->toEqual($accounts)
        ->and(SocialToken::where('provider_holder_id', 'fb-1')->count())->toBe(3)
        ->and(SocialToken::find($user)->ownable_type)->toBeNull();
});

it('gives credentials the owner key type of the accounts they copy it from', function () {
    // The app switched to uuid morphs after its accounts table was created.
    Schema::morphUsingUuids();

    try {
        ownerMigration()->up();
    } finally {
        Schema::defaultMorphKeyType('int');
    }

    expect(Schema::getColumnType('social_tokens', 'ownable_id'))->toBe(Schema::getColumnType('social_accounts', 'ownable_id'));
});

it('backfills uuid owners', function () {
    Schema::table('social_accounts', function (Blueprint $table) {
        $table->string('ownable_id')->nullable()->change();
    });
    Schema::morphUsingUuids();

    $workspace = (string) Str::uuid();
    $token = v1Credential('tiktok', 'tt-1', 'tt-access');
    DB::table('social_accounts')->insert([
        'provider' => 'tiktok', 'provider_user_id' => 'tt-1', 'provider_holder_id' => 'tt-1',
        'social_token_id' => $token, 'ownable_type' => 'workspace', 'ownable_id' => $workspace,
        'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    try {
        ownerMigration()->up();
    } finally {
        Schema::defaultMorphKeyType('int');
    }

    expect(DB::table('social_tokens')->where('id', $token)->value('ownable_id'))->toBe($workspace);
});

/** The owner key type the migration picks for an accounts column described like Schema::getColumns(). */
function morphTypeFor(string $typeName, string $type, string $driver, string $default = 'int'): string
{
    $migration = ownerMigration();
    Schema::defaultMorphKeyType($default);

    try {
        return (fn () => $this->morphTypeOf('social_accounts', ['type_name' => $typeName, 'type' => $type], $driver))->call($migration);
    } finally {
        Schema::defaultMorphKeyType('int');
    }
}

it('reads uuid and ulid owner keys from the accounts column, whatever the default', function (string $driver, string $typeName, string $type, string $default, string $expected) {
    expect(morphTypeFor($typeName, $type, $driver, $default))->toBe($expected);
})->with([
    'mysql uuid' => ['mysql', 'char', 'char(36)', 'ulid', 'uuid'],
    'mariadb native uuid' => ['mariadb', 'uuid', 'uuid', 'ulid', 'uuid'],
    'pgsql uuid' => ['pgsql', 'uuid', 'uuid', 'ulid', 'uuid'],
    'sqlsrv uuid' => ['sqlsrv', 'uniqueidentifier', 'uniqueidentifier', 'ulid', 'uuid'],
    'mysql ulid' => ['mysql', 'char', 'char(26)', 'uuid', 'ulid'],
    'pgsql ulid' => ['pgsql', 'bpchar', 'character(26)', 'uuid', 'ulid'],
    'sqlsrv ulid' => ['sqlsrv', 'nchar', 'nchar(26)', 'uuid', 'ulid'],
    'mysql bigint' => ['mysql', 'bigint', 'bigint unsigned', 'uuid', 'int'],
    'pgsql bigint' => ['pgsql', 'int8', 'bigint', 'uuid', 'int'],
    'sqlite integer' => ['sqlite', 'integer', 'integer', 'uuid', 'int'],
]);

it('takes the app morph key type for any other string column', function () {
    expect(morphTypeFor('varchar', 'varchar(255)', 'mysql', 'ulid'))->toBe('ulid');
});

it('refuses a string column it cannot read while morph keys default to integers', function (string $driver, string $typeName, string $type) {
    morphTypeFor($typeName, $type, $driver);
})->with([
    'mysql' => ['mysql', 'varchar', 'varchar(255)'],
    'pgsql' => ['pgsql', 'varchar', 'character varying(255)'],
])->throws(RuntimeException::class, 'Nothing was changed');

it('takes either string type on SQLite, which declares uuid and ulid columns alike', function () {
    expect(morphTypeFor('varchar', 'varchar', 'sqlite'))->toBeIn(['uuid', 'ulid']);
});

it('backfills owners of a per-column uuid morph on SQLite', function () {
    // nullableUuidMorphs('ownable') in a published copy of the accounts
    // migration, morph keys left to integers app-wide.
    Schema::table('social_accounts', function (Blueprint $table) {
        $table->string('ownable_id')->nullable()->change();
    });

    $workspace = (string) Str::uuid();
    $token = v1Credential('tiktok', 'tt-1', 'tt-access');
    DB::table('social_accounts')->insert([
        'provider' => 'tiktok', 'provider_user_id' => 'tt-1', 'provider_holder_id' => 'tt-1',
        'social_token_id' => $token, 'ownable_type' => 'workspace', 'ownable_id' => $workspace,
        'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    ownerMigration()->up();

    expect(Schema::getColumnType('social_tokens', 'ownable_id'))->toBe('varchar')
        ->and(DB::table('social_tokens')->where('id', $token)->value('ownable_id'))->toBe($workspace);
});

/** Run the migration's check that the accounts' owner ids fit the credentials' column. */
function checkOwnerIds(string $morphType, string $driver): void
{
    $migration = ownerMigration();

    (fn () => $this->ensureOwnerIdsFit('social_accounts', $morphType, $driver))->call($migration);
}

function accountOwnedBy(string $ownerId): void
{
    DB::table('social_accounts')->insert([
        'provider' => 'tiktok', 'provider_user_id' => $ownerId, 'ownable_type' => 'workspace', 'ownable_id' => $ownerId,
        'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('accepts owner ids that fit the credentials column', function (string $ownerId, string $morphType, string $driver) {
    Schema::table('social_accounts', fn (Blueprint $table) => $table->string('ownable_id')->nullable()->change());
    accountOwnedBy($ownerId);

    checkOwnerIds($morphType, $driver);

    expect(true)->toBeTrue();
})->with([
    'uuid, mysql' => [(string) Str::uuid(), 'uuid', 'mysql'],
    'uuid, pgsql' => [(string) Str::uuid(), 'uuid', 'pgsql'],
    'ulid' => [(string) Str::ulid(), 'ulid', 'pgsql'],
    'anything on sqlite' => [str_repeat('x', 40), 'ulid', 'sqlite'],
]);

it('refuses before changing anything when owner ids do not fit the credentials column', function (string $ownerId, string $morphType, string $driver) {
    Schema::table('social_accounts', fn (Blueprint $table) => $table->string('ownable_id')->nullable()->change());
    accountOwnedBy($ownerId);

    checkOwnerIds($morphType, $driver);
})->with([
    'too long for char(36)' => [str_repeat('x', 37), 'uuid', 'mysql'],
    'not a uuid for a uuid column' => ['42', 'uuid', 'pgsql'],
    'a uuid in a ulid column' => [(string) Str::uuid(), 'ulid', 'mysql'],
])->throws(RuntimeException::class, 'Nothing was changed');
