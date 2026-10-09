<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 3.0: `provider` holds a connector key ('facebook', 'linkedin'…), now at most
 * 50 characters. As varchar(255) it left no room on MySQL/MariaDB for a uuid
 * or ulid owner id in the owner-scoped unique keys: three utf8mb4 varchar(255)
 * columns already take 3060 of InnoDB's 3072 bytes.
 *
 * Named to sort before 2025_01_01_000007, which adds those keys: pending
 * migrations run in name order. A column without a declared length (SQLite,
 * which enforces none) is left alone.
 */
return new class extends Migration
{
    private const LENGTH = 50;

    public function up(): void
    {
        $tables = array_filter($this->tables(), fn (string $table) => $this->length($table) > self::LENGTH);

        // Check every table before changing any.
        array_map(fn (string $table) => $this->refuseLongerValues($table), $tables);

        foreach ($tables as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->string('provider', self::LENGTH)->change());
        }
    }

    public function down(): void
    {
        foreach ($this->tables() as $table) {
            $length = $this->length($table);

            if ($length !== null && $length < 255) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->string('provider')->change());
            }
        }
    }

    /** @return list<string> */
    private function tables(): array
    {
        return [config('social-tokens.table', 'social_accounts'), config('social-tokens.tokens_table', 'social_tokens')];
    }

    private function length(string $table): ?int
    {
        $column = collect(Schema::getColumns($table))->firstWhere('name', 'provider');

        return $column === null ? null : $this->declaredLength($column);
    }

    /** @param  array{type: string}  $column */
    private function declaredLength(array $column): ?int
    {
        return preg_match('/char(?:acter varying)?\((\d+)\)/i', $column['type'], $match) === 1 ? (int) $match[1] : null;
    }

    private function refuseLongerValues(string $table): void
    {
        $longer = DB::table($table)->distinct()->pluck('provider')
            ->filter(fn (string $provider) => mb_strlen($provider) > self::LENGTH);

        if ($longer->isNotEmpty()) {
            throw new RuntimeException(
                "Cannot make {$table}.provider varchar(".self::LENGTH.'): it holds longer connector keys ('
                .$longer->implode(', ').'). Rename those connectors to keys of at most '.self::LENGTH
                .' characters and update the rows, then migrate again. Nothing was changed.'
            );
        }
    }
};
