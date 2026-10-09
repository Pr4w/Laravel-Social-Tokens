<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * `migrate --pretend` runs a migration on a connection whose every query
 * returns no rows, so every table reads as empty. The guards of the 3.0
 * migrations took that for a broken schema ("social_accounts has no ownable_id
 * column") or for nothing to do, where 2.0.0 listed its DDL.
 */

function pretendedMigration(string $name): object
{
    return include __DIR__."/../../../database/migrations/{$name}.php";
}

/**
 * The statements a migration logs under pretend.
 *
 * @return list<string>
 */
function pretendedStatements(string $migration, string $method = 'up'): array
{
    $statements = array_column(DB::pretend(fn () => pretendedMigration($migration)->{$method}()), 'query');

    return array_values(array_filter($statements, fn (string $sql) => ! str_starts_with($sql, 'select')));
}

/**
 * Make the default connection a server nothing listens on: a statement that
 * really ran would fail to connect, so the pretend run must answer alone.
 */
function unreachableServer(string $driver): void
{
    config([
        'database.connections.unreachable' => [
            'driver' => $driver, 'host' => '127.0.0.1', 'port' => 1, 'database' => 'app',
            'username' => 'nobody', 'password' => '', 'prefix' => '', 'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci', 'search_path' => 'public',
        ],
        'database.default' => 'unreachable',
    ]);
}

it('lists the owner migration on the 1.x schema without changing it', function () {
    pretendedMigration('2025_01_01_000007_scope_identity_to_owner')->down();

    expect(pretendedStatements('2025_01_01_000007_scope_identity_to_owner'))->toBe([
        'alter table "social_tokens" add column "ownable_type" varchar',
        'alter table "social_tokens" add column "ownable_id" integer',
        'create index "social_tokens_ownable_type_ownable_id_index" on "social_tokens" ("ownable_type", "ownable_id")',
        'create unique index "social_tokens_owner_identity_unique" on "social_tokens" ("provider", "provider_holder_id", "ownable_type", "ownable_id")',
        'drop index "social_tokens_provider_provider_holder_id_unique"',
        'create unique index "social_accounts_owner_identity_unique" on "social_accounts" ("provider", "provider_user_id", "ownable_type", "ownable_id")',
        'drop index "social_accounts_provider_provider_user_id_unique"',
    ])->and(Schema::hasColumn('social_tokens', 'ownable_type'))->toBeFalse();
});

it('lists the owner migration rollback', function () {
    expect(pretendedStatements('2025_01_01_000007_scope_identity_to_owner', 'down'))->toBe([
        'create unique index "social_accounts_provider_provider_user_id_unique" on "social_accounts" ("provider", "provider_user_id")',
        'drop index "social_accounts_owner_identity_unique"',
        'create unique index "social_tokens_provider_provider_holder_id_unique" on "social_tokens" ("provider", "provider_holder_id")',
        'drop index "social_tokens_owner_identity_unique"',
        'drop index "social_tokens_ownable_type_ownable_id_index"',
        'alter table "social_tokens" drop column "ownable_type"',
        'alter table "social_tokens" drop column "ownable_id"',
    ])->and(Schema::hasColumn('social_tokens', 'ownable_type'))->toBeTrue();
});

it('lists the owner migration on MySQL, whose key length check reads the columns', function () {
    unreachableServer('mysql');

    expect(pretendedStatements('2025_01_01_000007_scope_identity_to_owner'))->toBe([
        'alter table `social_tokens` add `ownable_type` varchar(255) null',
        'alter table `social_tokens` add `ownable_id` bigint unsigned null',
        'alter table `social_tokens` add index `social_tokens_ownable_type_ownable_id_index`(`ownable_type`, `ownable_id`)',
        'alter table `social_tokens` add unique `social_tokens_owner_identity_unique`(`provider`, `provider_holder_id`, `ownable_type`, `ownable_id`)',
        'alter table `social_tokens` drop index `social_tokens_provider_provider_holder_id_unique`',
        'alter table `social_accounts` add unique `social_accounts_owner_identity_unique`(`provider`, `provider_user_id`, `ownable_type`, `ownable_id`)',
        'alter table `social_accounts` drop index `social_accounts_provider_provider_user_id_unique`',
    ]);
});

it('lists the owner columns with the app morph key type', function () {
    unreachableServer('pgsql');
    Schema::morphUsingUuids();

    try {
        $statements = pretendedStatements('2025_01_01_000007_scope_identity_to_owner');
    } finally {
        Schema::defaultMorphKeyType('int');
    }

    expect($statements)->toContain('alter table "social_tokens" add column "ownable_id" uuid null');
});

it('lists the provider column change it cannot measure', function (string $driver, array $expected) {
    unreachableServer($driver);

    expect(pretendedStatements('2025_01_01_000006_shorten_provider_columns'))->toBe($expected);
})->with([
    'mysql' => ['mysql', [
        'alter table `social_accounts` modify `provider` varchar(50) not null',
        'alter table `social_tokens` modify `provider` varchar(50) not null',
    ]],
    'pgsql' => ['pgsql', [
        'alter table "social_accounts" alter column "provider" type varchar(50), alter column "provider" set not null, alter column "provider" drop default, alter column "provider" drop identity if exists',
        'comment on column "social_accounts"."provider" is NULL',
        'alter table "social_tokens" alter column "provider" type varchar(50), alter column "provider" set not null, alter column "provider" drop default, alter column "provider" drop identity if exists',
        'comment on column "social_tokens"."provider" is NULL',
    ]],
]);

it('lists every migration of a fresh install', function (string $driver) {
    unreachableServer($driver);

    $statements = DB::pretend(function () {
        foreach (glob(__DIR__.'/../../../database/migrations/*.php') as $migration) {
            (include $migration)->up();
        }
    });

    expect(array_column($statements, 'query'))
        ->toContain(match ($driver) {
            'mysql' => 'alter table `social_accounts` add unique `social_accounts_owner_identity_unique`(`provider`, `provider_user_id`, `ownable_type`, `ownable_id`)',
            'pgsql' => 'alter table "social_accounts" add constraint "social_accounts_owner_identity_unique" unique ("provider", "provider_user_id", "ownable_type", "ownable_id")',
        });
})->with(['mysql', 'pgsql']);
