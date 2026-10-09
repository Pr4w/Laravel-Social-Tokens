<?php

use Illuminate\Support\Facades\DB;
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
    'sqlsrv' => ['nvarchar(255)', 255],
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
