<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;

/*
 * The owner-scoped unique keys of 2.0 carried hand-built names that ignored the
 * table prefix and kept the dot of a schema-qualified table. Two prefixed
 * installs sharing a PostgreSQL schema or an SQLite file collided on them, and
 * a `schema.table` name produced invalid SQL.
 */

beforeEach(function () {
    $this->database = tempnam(sys_get_temp_dir(), 'social-tokens-');

    foreach (['acme', 'beta'] as $prefix) {
        config(["database.connections.{$prefix}" => [
            'driver' => 'sqlite',
            'database' => $this->database,
            'prefix' => "{$prefix}_",
            'prefix_indexes' => true,
            'foreign_key_constraints' => true,
        ]]);
    }
});

afterEach(function () {
    DB::purge('acme');
    DB::purge('beta');
    @unlink($this->database);
});

/** Run every package migration (or one, in reverse) on the given connection. */
function onConnection(string $connection, Closure $callback): void
{
    $default = DB::getDefaultConnection();
    DB::setDefaultConnection($connection);

    try {
        $callback();
    } finally {
        DB::setDefaultConnection($default);
    }
}

function migratePackage(): void
{
    foreach (glob(__DIR__.'/../../../database/migrations/*.php') as $migration) {
        (include $migration)->up();
    }
}

function prefixOwnerMigration(): object
{
    return include __DIR__.'/../../../database/migrations/2025_01_01_000007_scope_identity_to_owner.php';
}

function uniqueIndexNames(string $connection, string $table): array
{
    return collect(Schema::connection($connection)->getIndexes($table))
        ->filter(fn (array $index) => $index['unique'] && ! $index['primary'])
        ->pluck('name')
        ->sort()
        ->values()
        ->all();
}

it('lets two prefixed installs share one database', function () {
    onConnection('acme', fn () => migratePackage());
    onConnection('beta', fn () => migratePackage());

    expect(uniqueIndexNames('beta', 'social_tokens'))->toBe(['beta_social_tokens_identity_purpose_unique'])
        ->and(uniqueIndexNames('beta', 'social_accounts'))->toBe(['beta_social_accounts_owner_identity_unique'])
        ->and(uniqueIndexNames('acme', 'social_tokens'))->toBe(['acme_social_tokens_identity_purpose_unique']);
});

it('rolls back the unprefixed indexes 2.0 created', function () {
    onConnection('acme', function () {
        migratePackage();
        (include __DIR__.'/../../../database/migrations/2025_01_01_000008_add_purpose_to_social_tokens.php')->down();

        // What 2.0.0 left on a prefixed install.
        foreach (['social_tokens' => 'provider_holder_id', 'social_accounts' => 'provider_user_id'] as $table => $external) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $external) {
                $blueprint->dropUnique("acme_{$table}_owner_identity_unique");
                $blueprint->unique(['provider', $external, 'ownable_type', 'ownable_id'], "{$table}_owner_identity_unique");
            });
        }

        prefixOwnerMigration()->down();
    });

    expect(uniqueIndexNames('acme', 'social_tokens'))->toBe(['acme_social_tokens_provider_provider_holder_id_unique'])
        ->and(uniqueIndexNames('acme', 'social_accounts'))->toBe(['acme_social_accounts_provider_provider_user_id_unique']);
});

it('names the owner keys the way Laravel names its own indexes', function (string $table) {
    $connection = DB::connection('acme');
    $migration = prefixOwnerMigration();

    $ours = null;
    onConnection('acme', function () use ($migration, $table, &$ours) {
        $ours = (fn () => $this->indexName($table, 'owner_identity_unique'))->call($migration);
    });

    $laravel = (fn () => $this->createIndexName('unique', ['owner', 'identity']))->call(new Blueprint($connection, $table));

    expect($ours)->toBe($laravel)->not->toContain('.');
})->with(['social_tokens', 'social.tokens']);

it('finds the credentials in use on a prefixed install', function () {
    // inUse() aliases the credentials table in a subquery: the prefix must
    // apply to the alias and to every column read through it.
    $inUse = null;

    onConnection('acme', function () use (&$inUse) {
        migratePackage();

        $user = SocialToken::create(['provider' => 'facebook', 'provider_holder_id' => 'fb-1', 'status' => 'active']);
        $page = SocialToken::create(['provider' => 'facebook', 'provider_holder_id' => 'page-1', 'status' => 'active']);
        SocialAccount::create(['provider' => 'facebook', 'provider_user_id' => 'page-1', 'provider_holder_id' => 'fb-1', 'social_token_id' => $page->getKey(), 'status' => 'active']);

        $inUse = SocialToken::query()->inUse()->orderBy('id')->pluck('provider_holder_id')->all();
    });

    expect($inUse)->toBe(['fb-1', 'page-1']);
});
