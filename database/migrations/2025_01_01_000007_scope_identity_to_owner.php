<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 2.0: identity is scoped to the owner.
 *
 * An account is unique per (provider, provider_user_id, owner) and a credential
 * per (provider, provider_holder_id, owner), so two owners connecting the same
 * Page, Instagram account, organization or TikTok account each keep their own
 * row and their own credential (the grant their own user gave).
 *
 * Existing credentials take the owner of the accounts they back. A credential
 * that backs the accounts of several owners (possible in 1.x after a partial
 * reconnect) is copied once per extra owner and those owners' accounts are
 * repointed to their copy. A credential backing no account stays owner-less.
 *
 * Safe to run again after a failure: MySQL commits every DDL statement on its
 * own, so a failure partway used to leave a schema the next run choked on.
 * Every step is guarded, the checks that can fail run before anything changes,
 * and the new unique keys are built before the old ones are dropped.
 *
 * Not one transaction: on PostgreSQL that held the ALTER's ACCESS EXCLUSIVE lock
 * on social_tokens, blocking every token read of the live app, until the last
 * credential was backfilled. The backfill commits in short chunks instead.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** InnoDB's index key limit (DYNAMIC/COMPRESSED rows, MySQL and MariaDB). */
    private const MYSQL_MAX_KEY_BYTES = 3072;

    private const CHUNK = 100;

    public function up(): void
    {
        $accounts = config('social-tokens.table', 'social_accounts');
        $tokens = config('social-tokens.tokens_table', 'social_tokens');

        $morphType = Schema::hasColumn($tokens, 'ownable_type') ? null : $this->ownerMorphType($accounts);
        $this->ensureOwnerKeysFit($accounts, $tokens);

        if ($morphType !== null) {
            Schema::table($tokens, fn (Blueprint $table) => match ($morphType) {
                'uuid' => $table->nullableUuidMorphs('ownable'),
                'ulid' => $table->nullableUlidMorphs('ownable'),
                default => $table->nullableNumericMorphs('ownable'),
            });
        } elseif (! Schema::hasIndex($tokens, ['ownable_type', 'ownable_id'])) {
            Schema::table($tokens, fn (Blueprint $table) => $table->index(['ownable_type', 'ownable_id']));
        }

        // The new keys first: each contains the old one, so the rows already
        // satisfy it, and a key the database rejects stops the run here.
        foreach ([[$tokens, 'provider_holder_id'], [$accounts, 'provider_user_id']] as [$table, $external]) {
            if ($this->uniqueIndexOn($table, ['provider', $external, 'ownable_type', 'ownable_id']) === null) {
                Schema::table($table, function (Blueprint $blueprint) use ($table, $external) {
                    $blueprint->unique(['provider', $external, 'ownable_type', 'ownable_id'], $this->indexName($table, 'owner_identity_unique'));
                });
            }

            // Two owners may now hold the same external identity, and splitting
            // a shared credential inserts a second row for the same holder.
            if ($old = $this->uniqueIndexOn($table, ['provider', $external])) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropUnique($old));
            }
        }

        // Each chunk commits whole, so a credential is never left half split:
        // one with an owner is done, and a run again picks up the others.
        DB::table($tokens)->whereNull('ownable_type')->orderBy('id')->pluck('id')
            ->chunk(self::CHUNK)
            ->each(fn ($ids) => DB::transaction(function () use ($ids, $accounts, $tokens) {
                foreach ($ids as $tokenId) {
                    $this->assignOwners($accounts, $tokens, (int) $tokenId);
                }
            }));
    }

    public function down(): void
    {
        $accounts = config('social-tokens.table', 'social_accounts');
        $tokens = config('social-tokens.tokens_table', 'social_tokens');

        foreach ([[$accounts, 'provider_user_id'], [$tokens, 'provider_holder_id']] as [$table, $column]) {
            $shared = DB::table($table)
                ->select('provider', $column)
                ->groupBy('provider', $column)
                ->havingRaw('count(*) > 1')
                ->exists();

            if ($shared) {
                throw new RuntimeException(
                    "Cannot roll back: several owners hold the same ({$table}.provider, {$table}.{$column}). "
                    .'Remove or merge those rows first; 1.x allows one row per external identity.'
                );
            }
        }

        foreach ([[$accounts, 'provider_user_id'], [$tokens, 'provider_holder_id']] as [$table, $external]) {
            if ($this->uniqueIndexOn($table, ['provider', $external]) === null) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unique(['provider', $external]));
            }

            // Looked up by columns: 2.0.0 named it without the table prefix.
            if ($new = $this->uniqueIndexOn($table, ['provider', $external, 'ownable_type', 'ownable_id'])) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropUnique($new));
            }
        }

        if (Schema::hasColumn($tokens, 'ownable_type')) {
            Schema::table($tokens, fn (Blueprint $table) => $table->dropMorphs('ownable'));
        }
    }

    /**
     * Give a credential its accounts' owner, copying it for every extra owner.
     * Raw rows only: the encrypted columns are copied as stored, never decrypted.
     */
    private function assignOwners(string $accounts, string $tokens, int $tokenId): void
    {
        $token = DB::table($tokens)->where('id', $tokenId)->first();

        if ($token === null || $token->ownable_type !== null) {
            return; // gone, or done by an earlier run
        }

        // Accounts posting with it; failing that, accounts naming it as their
        // holder (the Meta user credential behind a user's Facebook Pages).
        $byToken = DB::table($accounts)->where('social_token_id', $tokenId);
        $viaHolder = ! $byToken->clone()->exists();

        if ($viaHolder && $token->provider_holder_id === null) {
            return; // an account without a holder names no credential: stays owner-less
        }

        $source = $viaHolder
            ? DB::table($accounts)->where('provider', $token->provider)->where('provider_holder_id', $token->provider_holder_id)
            : $byToken;

        $owners = $source->clone()
            ->whereNotNull('ownable_type')
            ->select('ownable_type', 'ownable_id')
            ->distinct()
            ->orderBy('ownable_type')
            ->orderBy('ownable_id')
            ->get();

        if ($owners->isEmpty()) {
            return; // backs no owned account: stays owner-less
        }

        // Owner-less accounts keep the original row, which then stays
        // owner-less (and is looked at again by a later run).
        if (! $source->clone()->whereNull('ownable_type')->exists()) {
            $first = $owners->shift();

            DB::table($tokens)->where('id', $tokenId)->update([
                'ownable_type' => $first->ownable_type,
                'ownable_id' => $first->ownable_id,
            ]);
        }

        foreach ($owners as $owner) {
            $copyId = $this->copyFor($tokens, $token, $owner);

            if (! $viaHolder) {
                $this->whereOwner(DB::table($accounts)->where('social_token_id', $tokenId), $owner)
                    ->update(['social_token_id' => $copyId]);
            }
        }
    }

    /**
     * The owner's copy of a credential. The old unique key allowed one row per
     * holder, so the owner's row for this holder was copied by an earlier run
     * (and the new key allows no second one).
     *
     * That key let any number of holder-less rows through, so another one is
     * a different grant, never a copy. Nothing needs reusing there: a holder-less
     * credential is reached only through the accounts posting with it, which
     * move to their copies in the same transaction.
     */
    private function copyFor(string $tokens, object $token, object $owner): int
    {
        $existing = $token->provider_holder_id === null ? null : $this->whereOwner(DB::table($tokens), $owner)
            ->where('provider', $token->provider)
            ->where('provider_holder_id', $token->provider_holder_id)
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        $copy = (array) $token;
        unset($copy['id']);
        $copy['ownable_type'] = $owner->ownable_type;
        $copy['ownable_id'] = $owner->ownable_id;

        return (int) DB::table($tokens)->insertGetId($copy);
    }

    private function whereOwner(Builder $query, object $owner): Builder
    {
        return $owner->ownable_type === null
            ? $query->whereNull('ownable_type')->whereNull('ownable_id')
            : $query->where('ownable_type', $owner->ownable_type)->where('ownable_id', $owner->ownable_id);
    }

    /**
     * The key type of the credentials' owner: the accounts' own, since the
     * backfill copies their owners. Checked before anything changes: a wrong
     * type fails the backfill after the column is added.
     */
    private function ownerMorphType(string $accounts): string
    {
        $driver = Schema::getConnection()->getDriverName();
        $column = collect(Schema::getColumns($accounts))->firstWhere('name', 'ownable_id')
            ?? throw new RuntimeException("{$accounts} has no ownable_id column.");

        $morphType = $this->morphTypeOf($accounts, $column, $driver);

        if ($morphType !== 'int') {
            $this->ensureOwnerIdsFit($accounts, $morphType, $driver);
        }

        return $morphType;
    }

    /**
     * Read from the column first, so uuid or ulid owners declared per column
     * (nullableUuidMorphs('ownable')) work as well as app-wide ones. Only a
     * string column Laravel's uuid and ulid types do not produce falls back to
     * the app's morph key type.
     *
     * @param  array{type_name: string, type: string}  $column  as Schema::getColumns() describes it
     */
    private function morphTypeOf(string $accounts, array $column, string $driver): string
    {
        $typeName = strtolower($column['type_name']);
        $type = strtolower($column['type']);

        if (preg_match('/^(tiny|small|medium|big)?int(eger|[248])?$/', $typeName) === 1) {
            return 'int';
        }

        // Laravel's uuid column is native or char(36), its ulid column char(26).
        if (in_array($typeName, ['uuid', 'uniqueidentifier'], true) || preg_match('/^n?char(?:acter)?\(36\)/', $type) === 1) {
            return 'uuid';
        }

        if (preg_match('/^n?char(?:acter)?\(26\)/', $type) === 1) {
            return 'ulid';
        }

        if (SchemaBuilder::$defaultMorphKeyType !== 'int') {
            return SchemaBuilder::$defaultMorphKeyType;
        }

        // SQLite declares uuid and ulid columns alike (varchar) and enforces neither.
        if ($driver === 'sqlite') {
            return 'uuid';
        }

        throw new RuntimeException(
            "{$accounts}.ownable_id holds non-integer keys ({$type}), but morph keys default to integers here. "
            .'Call Schema::morphUsingUuids() or Schema::morphUsingUlids(), matching your owner keys, in a service '
            .'provider, then migrate again. Nothing was changed.'
        );
    }

    /**
     * Every owner id of the accounts must fit the credentials' owner column, or
     * the backfill fails halfway. A native uuid column (PostgreSQL, MariaDB,
     * SQL Server) takes nothing but uuids.
     */
    private function ensureOwnerIdsFit(string $accounts, string $morphType, string $driver): void
    {
        if ($driver === 'sqlite') {
            return; // enforces no type or length
        }

        $fits = match (true) {
            $morphType === 'ulid' => fn (string $id) => mb_strlen($id) <= 26,
            in_array($driver, ['pgsql', 'mariadb', 'sqlsrv'], true) => fn (string $id) => Str::isUuid($id),
            default => fn (string $id) => mb_strlen($id) <= 36,
        };

        $misfit = DB::table($accounts)->whereNotNull('ownable_id')->distinct()->pluck('ownable_id')
            ->map(fn (mixed $id) => (string) $id)
            ->first(fn (string $id) => ! $fits($id));

        if ($misfit !== null) {
            throw new RuntimeException(
                "{$accounts}.ownable_id holds an owner id a {$morphType} column cannot store ('{$misfit}'), and "
                ."the credentials' owner column would be one. Make Schema::morphUsingUuids() or "
                .'Schema::morphUsingUlids() match your owner keys, or store those owners with keys of that type, '
                .'then migrate again. Nothing was changed.'
            );
        }
    }

    /**
     * MySQL/MariaDB reject an index key over 3072 bytes, and commit every DDL
     * statement on its own: find out before changing anything.
     */
    private function ensureOwnerKeysFit(string $accounts, string $tokens): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $accountColumns = collect(Schema::getColumns($accounts))->keyBy('name')->all();

        // Until they exist, the credential's owner columns mirror the accounts'.
        $tokenColumns = collect(Schema::getColumns($tokens))->keyBy('name')->all()
            + ['ownable_type' => $accountColumns['ownable_type'], 'ownable_id' => $accountColumns['ownable_id']];

        foreach ([[$tokens, $tokenColumns, 'provider_holder_id'], [$accounts, $accountColumns, 'provider_user_id']] as [$table, $columns, $external]) {
            $this->ensureOwnerKeyFits($table, array_map(
                fn (string $name) => $columns[$name],
                ['provider', $external, 'ownable_type', 'ownable_id'],
            ));
        }
    }

    /**
     * @param  list<array{type: string, collation: ?string}>  $columns  as Schema::getColumns() describes them
     */
    private function ensureOwnerKeyFits(string $table, array $columns): void
    {
        $bytes = 0;

        foreach ($columns as $column) {
            $size = $this->keyBytes($column['type'], $column['collation'] ?? null);

            if ($size === null) {
                return; // a type we cannot size: leave it to the database
            }

            $bytes += $size;
        }

        if ($bytes > self::MYSQL_MAX_KEY_BYTES) {
            throw new RuntimeException(
                "The owner-scoped unique key of {$table} would take {$bytes} bytes, over InnoDB's "
                .self::MYSQL_MAX_KEY_BYTES.'-byte limit. Make provider varchar(50) (the '
                .'2025_01_01_000006_shorten_provider_columns migration does it; publish it if you run published '
                .'copies) or shorten the provider id columns, then migrate again. Nothing was changed.'
            );
        }
    }

    private function keyBytes(string $type, ?string $collation): ?int
    {
        $type = strtolower($type);

        if (preg_match('/^(?:var)?char\((\d+)\)/', $type, $match) === 1) {
            return (int) $match[1] * $this->bytesPerCharacter($collation);
        }

        if (preg_match('/^(?:var)?binary\((\d+)\)/', $type, $match) === 1) {
            return (int) $match[1];
        }

        if (preg_match('/^(tiny|small|medium|big)?int\b/', $type, $match) === 1) {
            return ['tiny' => 1, 'small' => 2, 'medium' => 3, 'big' => 8][$match[1] ?? ''] ?? 4;
        }

        return $type === 'uuid' ? 16 : null; // MariaDB's native uuid
    }

    /** The widest character of the collation's character set (MySQL's maxlen). */
    private function bytesPerCharacter(?string $collation): int
    {
        if ($collation === null) {
            return 4;
        }

        $charset = strstr($collation, '_', true) ?: $collation;

        return [
            'utf8mb4' => 4, 'utf16' => 4, 'utf16le' => 4, 'utf32' => 4, 'gb18030' => 4,
            'utf8mb3' => 3, 'utf8' => 3, 'eucjpms' => 3, 'ujis' => 3,
            'ucs2' => 2, 'big5' => 2, 'cp932' => 2, 'euckr' => 2, 'gb2312' => 2, 'gbk' => 2, 'sjis' => 2,
        ][$charset] ?? 1;
    }

    /** @param  list<string>  $columns */
    private function uniqueIndexOn(string $table, array $columns): ?string
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['unique'] && ! $index['primary'] && $index['columns'] === $columns) {
                return $index['name'];
            }
        }

        return null;
    }

    /**
     * Named like Laravel names its own indexes (table prefix, no schema dot):
     * the generated name would pass MySQL's 64 characters.
     */
    private function indexName(string $table, string $suffix): string
    {
        $connection = Schema::getConnection();

        if ($connection->getConfig('prefix_indexes')) {
            $prefix = $connection->getTablePrefix();
            $dot = strrpos($table, '.');
            $table = $dot === false ? $prefix.$table : substr_replace($table, '.'.$prefix, $dot, 1);
        }

        return str_replace(['-', '.'], '_', strtolower("{$table}_{$suffix}"));
    }
};
