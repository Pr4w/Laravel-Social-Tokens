<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pr4w\SocialTokens\Models\SocialAccount;

/*
 * `provider` holds a connector key ('facebook', 'linkedin'…). As a 255-character
 * column it left no room in the owner-scoped unique keys for a uuid or ulid
 * owner id on MySQL, so it becomes varchar(50) before 000007 adds them.
 */

function providerLengthMigration(): object
{
    return include __DIR__.'/../../../database/migrations/2025_01_01_000006_shorten_provider_columns.php';
}

/**
 * Point the package at tables whose provider column declares the given type.
 * SQLite keeps a declared length (without enforcing it), which the schema
 * builder never writes there.
 */
function declaredProviderTables(string $type): void
{
    foreach (['declared_accounts', 'declared_tokens'] as $table) {
        DB::statement("create table {$table} (id integer primary key, provider {$type} not null)");
    }

    config(['social-tokens.table' => 'declared_accounts', 'social-tokens.tokens_table' => 'declared_tokens']);
}

/** @return list<string> */
function declaredProviderTypes(): array
{
    return [
        Schema::getColumnType('declared_accounts', 'provider', true),
        Schema::getColumnType('declared_tokens', 'provider', true),
    ];
}

it('runs before the migration that adds the owner-scoped keys', function () {
    $files = array_map('basename', glob(__DIR__.'/../../../database/migrations/*.php'));

    expect($files)->toContain('2025_01_01_000006_shorten_provider_columns.php')
        ->and(array_search('2025_01_01_000006_shorten_provider_columns.php', $files))
        ->toBeLessThan(array_search('2025_01_01_000007_scope_identity_to_owner.php', $files));
});

it('reads the declared length of the column', function (string $type, ?int $length) {
    $migration = providerLengthMigration();

    expect((fn () => $this->declaredLength(['type' => $type]))->call($migration))->toBe($length);
})->with([
    'mysql' => ['varchar(255)', 255],
    'pgsql' => ['character varying(255)', 255],
    'shortened' => ['varchar(50)', 50],
    'sqlite, no length' => ['varchar', null],
]);

it('refuses to shorten a column holding a longer provider', function () {
    SocialAccount::create(['provider' => str_repeat('p', 51), 'provider_user_id' => '1']);
    $migration = providerLengthMigration();

    (fn () => $this->refuseLongerValues('social_accounts'))->call($migration);
})->throws(RuntimeException::class, str_repeat('p', 51));

it('accepts the connector keys', function () {
    SocialAccount::create(['provider' => 'facebook', 'provider_user_id' => '1']);
    $migration = providerLengthMigration();

    (fn () => $this->refuseLongerValues('social_accounts'))->call($migration);

    expect(DB::table('social_accounts')->count())->toBe(1);
});

it('leaves a column without a declared length alone', function () {
    // SQLite declares plain varchar and does not enforce lengths anyway.
    SocialAccount::create(['provider' => str_repeat('p', 51), 'provider_user_id' => '1']);

    providerLengthMigration()->up();
    providerLengthMigration()->down();

    expect(DB::table('social_accounts')->value('provider'))->toBe(str_repeat('p', 51));
});

it('changes the column only where InnoDB caps the key length', function () {
    // SQLite here. SQL Server, which has no such cap, cannot shrink a column an
    // index uses in place either.
    declaredProviderTables('varchar(255)');

    providerLengthMigration()->up();

    expect(declaredProviderTypes())->toBe(['varchar(255)', 'varchar(255)']);
});

it('keeps the shorter column on rollback', function () {
    // 2.x reads varchar(50) fine, and widening it again could push the owner
    // keys of a uuid install back over InnoDB's limit.
    declaredProviderTables('varchar(50)');

    providerLengthMigration()->down();

    expect(declaredProviderTypes())->toBe(['varchar(50)', 'varchar(50)']);
});
