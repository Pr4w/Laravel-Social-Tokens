<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function dataAccessMigration(): object
{
    return include __DIR__.'/../../../database/migrations/2025_01_01_000009_add_data_access_expiry_to_social_tokens.php';
}

it('adds the data access columns, and can be run again', function () {
    dataAccessMigration()->up(); // already run by the test case

    expect(Schema::hasColumns('social_tokens', ['data_access_expires_at', 'data_access_warned_at']))->toBeTrue();
});

it('resumes after a run that stopped halfway', function () {
    dataAccessMigration()->down();
    Schema::table('social_tokens', fn ($table) => $table->timestamp('data_access_expires_at')->nullable());

    dataAccessMigration()->up();

    expect(Schema::hasColumns('social_tokens', ['data_access_expires_at', 'data_access_warned_at']))->toBeTrue();
});

it('rolls back, twice over', function () {
    dataAccessMigration()->down();
    dataAccessMigration()->down();

    expect(Schema::hasColumn('social_tokens', 'data_access_expires_at'))->toBeFalse()
        ->and(Schema::hasColumn('social_tokens', 'data_access_warned_at'))->toBeFalse();
});

it('lists its statements under pretend on MySQL and PostgreSQL', function (string $driver, array $up, array $down) {
    config([
        'database.connections.unreachable' => [
            'driver' => $driver, 'host' => '127.0.0.1', 'port' => 1, 'database' => 'app',
            'username' => 'nobody', 'password' => '', 'prefix' => '', 'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci', 'search_path' => 'public',
        ],
        'database.default' => 'unreachable',
    ]);

    expect(array_column(DB::pretend(fn () => dataAccessMigration()->up()), 'query'))->toBe($up)
        ->and(array_column(DB::pretend(fn () => dataAccessMigration()->down()), 'query'))->toBe($down);
})->with([
    'mysql' => ['mysql', [
        'alter table `social_tokens` add `data_access_expires_at` timestamp null',
        'alter table `social_tokens` add `data_access_warned_at` timestamp null',
    ], [
        'alter table `social_tokens` drop `data_access_warned_at`',
        'alter table `social_tokens` drop `data_access_expires_at`',
    ]],
    'pgsql' => ['pgsql', [
        'alter table "social_tokens" add column "data_access_expires_at" timestamp(0) without time zone null',
        'alter table "social_tokens" add column "data_access_warned_at" timestamp(0) without time zone null',
    ], [
        'alter table "social_tokens" drop column "data_access_warned_at"',
        'alter table "social_tokens" drop column "data_access_expires_at"',
    ]],
]);
