<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * social_accounts.social_token_id is how a credential finds its accounts (the
 * dispatcher's inUse(), revoke(), disconnect(), the ON DELETE SET NULL check,
 * the owner backfill). PostgreSQL and SQLite do not index a foreign key column
 * on their own, so every one of those lookups scanned the whole table.
 */

function tokenIdIndexMigration(): object
{
    return include __DIR__.'/../../../database/migrations/2025_01_01_000006_index_social_token_id_on_social_accounts.php';
}

/** Names of the indexes whose first column is social_token_id. */
function tokenIdIndexes(): array
{
    return collect(Schema::getIndexes('social_accounts'))
        ->filter(fn (array $index) => $index['columns'][0] === 'social_token_id')
        ->pluck('name')
        ->all();
}

it('indexes the credential of each account', function () {
    $plan = collect(DB::select('explain query plan select * from social_accounts where social_token_id = 1'))->pluck('detail')->implode(' ');

    expect(tokenIdIndexes())->toBe(['social_accounts_social_token_id_index'])
        ->and($plan)->toContain('USING INDEX social_accounts_social_token_id_index');
});

it('runs before the owner backfill, which looks accounts up by credential', function () {
    $files = array_map('basename', glob(__DIR__.'/../../../database/migrations/*.php'));

    expect(array_search('2025_01_01_000006_index_social_token_id_on_social_accounts.php', $files))
        ->toBeLessThan(array_search('2025_01_01_000007_scope_identity_to_owner.php', $files));
});

it('can run again', function () {
    tokenIdIndexMigration()->up();

    expect(tokenIdIndexes())->toBe(['social_accounts_social_token_id_index']);
});

it('adds nothing where an index already leads with social_token_id', function () {
    // MySQL creates one for the foreign key.
    tokenIdIndexMigration()->down();
    DB::statement('create index social_accounts_social_token_id_foreign on social_accounts (social_token_id)');

    tokenIdIndexMigration()->up();

    expect(tokenIdIndexes())->toBe(['social_accounts_social_token_id_foreign']);

    tokenIdIndexMigration()->down();

    expect(tokenIdIndexes())->toBe(['social_accounts_social_token_id_foreign']);
});

it('drops its index on rollback, and can roll back twice', function () {
    tokenIdIndexMigration()->down();
    tokenIdIndexMigration()->down();

    expect(tokenIdIndexes())->toBe([]);
});
