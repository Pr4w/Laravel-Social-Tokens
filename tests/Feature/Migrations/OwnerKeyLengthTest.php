<?php

/*
 * MySQL/MariaDB cap an InnoDB index key at 3072 bytes. With utf8mb4 three
 * varchar(255) columns already take 3060, so a uuid (char 36) or ulid (char 26)
 * owner id pushed the owner-scoped unique keys over, and 2.0's migration failed
 * on them after its destructive steps. The keys must fit once `provider` is
 * varchar(50), and the migration must refuse up front when they would not.
 */

function ownerKeyMigration(): object
{
    return include __DIR__.'/../../../database/migrations/2025_01_01_000007_scope_identity_to_owner.php';
}

/** A column as Schema::getColumns() describes it on MySQL. */
function mysqlColumn(string $type, ?string $collation = 'utf8mb4_unicode_ci'): array
{
    return ['type' => $type, 'collation' => str_contains($type, 'char') ? $collation : null];
}

/** Run the migration's key-length check on MySQL-shaped key columns. */
function checkOwnerKey(string $provider, string $ownableId, string $collation = 'utf8mb4_unicode_ci'): void
{
    $migration = ownerKeyMigration();

    (fn () => $this->ensureOwnerKeyFits('social_tokens', [
        mysqlColumn($provider, $collation),
        mysqlColumn('varchar(255)', $collation),
        mysqlColumn('varchar(255)', $collation),
        mysqlColumn($ownableId, $collation),
    ]))->call($migration);
}

it('refuses uuid owner ids next to a 255-character provider', function () {
    checkOwnerKey('varchar(255)', 'char(36)');
})->throws(RuntimeException::class, '3204 bytes');

it('refuses ulid owner ids next to a 255-character provider', function () {
    checkOwnerKey('varchar(255)', 'char(26)');
})->throws(RuntimeException::class, '3164 bytes');

it('says how to make the key fit', function () {
    checkOwnerKey('varchar(255)', 'char(36)');
})->throws(RuntimeException::class, 'Nothing was changed');

it('fits uuid and ulid owner ids once provider is varchar(50)', function (string $ownableId) {
    checkOwnerKey('varchar(50)', $ownableId);

    expect(true)->toBeTrue();
})->with(['char(36)', 'char(26)']);

it('fits the default bigint owner ids', function () {
    checkOwnerKey('varchar(255)', 'bigint unsigned'); // 3068 bytes

    expect(true)->toBeTrue();
});

it('counts the bytes per character of the column collation', function (string $collation) {
    checkOwnerKey('varchar(255)', 'char(36)', $collation);

    expect(true)->toBeTrue();
})->with(['utf8mb3_general_ci', 'latin1_swedish_ci', 'ascii_bin']);

it('leaves a column type it does not know to the database', function () {
    checkOwnerKey('varchar(255)', 'geometry');

    expect(true)->toBeTrue();
});
