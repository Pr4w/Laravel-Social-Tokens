<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3.0: index the credential each account posts with. PostgreSQL and SQLite do
 * not index a foreign key column on their own, so every lookup of a
 * credential's accounts (the dispatcher's inUse(), revoke(), disconnect(), the
 * ON DELETE SET NULL check) scanned the whole table. MySQL already has one for
 * the foreign key, which this leaves as it is.
 *
 * Named to sort before 2025_01_01_000007, whose backfill looks accounts up by
 * credential: pending migrations run in name order.
 */
return new class extends Migration
{
    public function up(): void
    {
        $accounts = config('social-tokens.table', 'social_accounts');

        foreach (Schema::getIndexes($accounts) as $index) {
            if ($index['columns'][0] === 'social_token_id') {
                return;
            }
        }

        Schema::table($accounts, fn (Blueprint $table) => $table->index('social_token_id', $this->indexName($accounts)));
    }

    public function down(): void
    {
        $accounts = config('social-tokens.table', 'social_accounts');

        if (Schema::hasIndex($accounts, $this->indexName($accounts))) {
            Schema::table($accounts, fn (Blueprint $table) => $table->dropIndex($this->indexName($accounts)));
        }
    }

    /** Laravel's own name for it, spelled out so down() can tell it from MySQL's. */
    private function indexName(string $table): string
    {
        $connection = Schema::getConnection();

        if ($connection->getConfig('prefix_indexes')) {
            $prefix = $connection->getTablePrefix();
            $dot = strrpos($table, '.');
            $table = $dot === false ? $prefix.$table : substr_replace($table, '.'.$prefix, $dot, 1);
        }

        return str_replace(['-', '.'], '_', strtolower("{$table}_social_token_id_index"));
    }
};
