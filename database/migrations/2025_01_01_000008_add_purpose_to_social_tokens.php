<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 3.0: a credential is unique per (provider, provider_holder_id, purpose,
 * owner). `purpose` tells apart the grants one external holder gives the same
 * owner through different flows: '' is the default credential, and
 * 'organizations' the LinkedIn member token behind the organizations
 * (StoreLinkedInOrganizations). The member's personal profile
 * (StoreAccountFromSocialite) shared that credential, so whichever flow ran
 * last replaced the other's token, refresh token and scopes.
 *
 * Existing LinkedIn credentials are split. One that backs organization rows
 * and anything else (the member's personal row) is copied with purpose
 * 'organizations', and the organization rows move to the copy; one that backs
 * organization rows only takes that purpose in place. A copy holds the tokens
 * the two flows shared, as stored (encrypted columns are never decrypted).
 *
 * Only with single authorization mode off (connectors.linkedin.
 * single_authorization, LINKEDIN_SINGLE_AUTHORIZATION=false when this runs).
 * In that mode, the default since 3.2, the profile and the organizations come
 * from one authorization and keep the credential they share. Turned on
 * later, social-tokens:merge-linkedin-credentials folds the split
 * credentials back.
 *
 * Safe to run again after a failure, like 2025_01_01_000007: every step is
 * guarded, the key length is checked before anything changes, the new unique
 * key is built before the old one is dropped (the rows satisfy it: every
 * purpose is '' until the split), and the split commits per chunk, a
 * credential whole or not at all. Not one transaction, so PostgreSQL does not
 * hold the ALTER's lock on social_tokens through the split.
 *
 * Under `migrate --pretend` every query returns no rows: it lists the
 * statements for the schema 000007 leaves, and skips the checks and the
 * split.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const KEY = ['provider', 'provider_holder_id', 'purpose', 'ownable_type', 'ownable_id'];

    /** The owner-scoped key of 2025_01_01_000007, which this one replaces. */
    private const OLD_KEY = ['provider', 'provider_holder_id', 'ownable_type', 'ownable_id'];

    private const LENGTH = 32;

    private const ORGANIZATIONS = 'organizations';

    /** InnoDB's index key limit (DYNAMIC/COMPRESSED rows, MySQL and MariaDB). */
    private const MYSQL_MAX_KEY_BYTES = 3072;

    private const CHUNK = 100;

    public function up(): void
    {
        $tokens = config('social-tokens.tokens_table', 'social_tokens');
        $accounts = config('social-tokens.table', 'social_accounts');
        $pretending = Schema::getConnection()->pretending();

        if (! $pretending) {
            $this->ensureKeyFits($tokens);
        }

        if ($pretending || ! Schema::hasColumn($tokens, 'purpose')) {
            Schema::table($tokens, fn (Blueprint $table) => $table->string('purpose', self::LENGTH)->default('')->after('provider_holder_id'));
        }

        if ($pretending || $this->uniqueIndexOn($tokens, self::KEY) === null) {
            Schema::table($tokens, fn (Blueprint $table) => $table->unique(self::KEY, $this->indexName($tokens, 'identity_purpose_unique')));
        }

        // Found by its columns: 2.0.0 named it without the table prefix.
        $old = $pretending ? $this->indexName($tokens, 'owner_identity_unique') : $this->uniqueIndexOn($tokens, self::OLD_KEY);

        if ($old !== null) {
            Schema::table($tokens, fn (Blueprint $table) => $table->dropUnique($old));
        }

        // Nothing to split in no rows (and logging the lookup's bindings would
        // need a real connection), nor when the profile and the organizations
        // come from one authorization.
        if ($pretending || $this->singleAuthorization()) {
            return;
        }

        DB::table($tokens)
            ->where('provider', 'linkedin')
            ->where('purpose', '')
            ->whereNotNull('provider_holder_id')
            ->orderBy('id')
            ->pluck('id')
            ->chunk(self::CHUNK)
            ->each(fn ($ids) => DB::transaction(function () use ($ids, $tokens, $accounts) {
                foreach ($ids as $tokenId) {
                    $this->split($tokens, $accounts, (int) $tokenId);
                }
            }));
    }

    /**
     * Refuses while a holder has several credentials for one owner: 2.x keeps
     * one, and would pick either.
     */
    public function down(): void
    {
        $tokens = config('social-tokens.tokens_table', 'social_tokens');
        $pretending = Schema::getConnection()->pretending();

        if (! $pretending && Schema::hasColumn($tokens, 'purpose')) {
            $split = DB::table($tokens)
                ->whereNotNull('provider_holder_id')
                ->select('provider', 'provider_holder_id', 'ownable_type', 'ownable_id')
                ->groupBy('provider', 'provider_holder_id', 'ownable_type', 'ownable_id')
                ->havingRaw('count(*) > 1')
                ->exists();

            if ($split) {
                throw new RuntimeException(
                    "Cannot roll back: a holder has several credentials for one owner in {$tokens} (the LinkedIn "
                    ."organizations' credential next to the member's personal one, purpose 'organizations'). Merge or "
                    .'remove those rows first, repointing their accounts; 2.x keeps one credential per holder and owner.'
                );
            }
        }

        if ($pretending || $this->uniqueIndexOn($tokens, self::OLD_KEY) === null) {
            Schema::table($tokens, fn (Blueprint $table) => $table->unique(self::OLD_KEY, $this->indexName($tokens, 'owner_identity_unique')));
        }

        $new = $pretending ? $this->indexName($tokens, 'identity_purpose_unique') : $this->uniqueIndexOn($tokens, self::KEY);

        if ($new !== null) {
            Schema::table($tokens, fn (Blueprint $table) => $table->dropUnique($new));
        }

        if ($pretending || Schema::hasColumn($tokens, 'purpose')) {
            Schema::table($tokens, fn (Blueprint $table) => $table->dropColumn('purpose'));
        }
    }

    /** Read like every boolean setting: LINKEDIN_SINGLE_AUTHORIZATION=off is the string 'off'. */
    private function singleAuthorization(): bool
    {
        $value = config('social-tokens.connectors.linkedin.single_authorization') ?? true;

        return is_array($value) || is_object($value) || (filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true);
    }

    /**
     * Give the organization rows of a LinkedIn credential their own one. An
     * organization row is a LinkedIn account other than the member's own
     * (its provider_user_id is not the member id).
     */
    private function split(string $tokens, string $accounts, int $tokenId): void
    {
        $token = DB::table($tokens)->where('id', $tokenId)->first();

        if ($token === null || $token->purpose !== '') {
            return; // gone, or done by an earlier run
        }

        $organizations = DB::table($accounts)
            ->where('social_token_id', $tokenId)
            ->where('provider', 'linkedin')
            ->where('provider_user_id', '!=', $token->provider_holder_id);

        $organizationCount = $organizations->clone()->count();

        if ($organizationCount === 0) {
            return; // the personal profile's, or backs nothing: the default credential
        }

        $existing = $this->whereOwner(DB::table($tokens), $token)
            ->where('provider', $token->provider)
            ->where('provider_holder_id', $token->provider_holder_id)
            ->where('purpose', self::ORGANIZATIONS)
            ->value('id');

        $backsOthers = DB::table($accounts)->where('social_token_id', $tokenId)->count() > $organizationCount;

        if ($existing === null && ! $backsOthers) {
            DB::table($tokens)->where('id', $tokenId)->update(['purpose' => self::ORGANIZATIONS]);

            return;
        }

        $organizations->update(['social_token_id' => $existing ?? $this->copy($tokens, $token)]);
    }

    /** Raw rows only: the encrypted columns are copied as stored, never decrypted. */
    private function copy(string $tokens, object $token): int
    {
        $copy = (array) $token;
        unset($copy['id']);
        $copy['purpose'] = self::ORGANIZATIONS;

        return (int) DB::table($tokens)->insertGetId($copy);
    }

    private function whereOwner(Builder $query, object $owner): Builder
    {
        return $owner->ownable_type === null
            ? $query->whereNull('ownable_type')->whereNull('ownable_id')
            : $query->where('ownable_type', $owner->ownable_type)->where('ownable_id', $owner->ownable_id);
    }

    /**
     * MySQL/MariaDB reject an index key over 3072 bytes, and commit every DDL
     * statement on its own: find out before changing anything. A column not
     * there yet is sized as this migration makes it.
     */
    private function ensureKeyFits(string $tokens): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $columns = collect(Schema::getColumns($tokens))->keyBy('name')->all();
        $columns['purpose'] ??= ['type' => 'varchar('.self::LENGTH.')', 'collation' => $columns['provider']['collation'] ?? null];

        $this->ensureKeyColumnsFit($tokens, $columns);
    }

    /**
     * @param  array<string, array{type: string, collation?: ?string}>  $columns  as Schema::getColumns() describes them, by name
     */
    private function ensureKeyColumnsFit(string $tokens, array $columns): void
    {
        $bytes = 0;

        foreach (self::KEY as $name) {
            $size = isset($columns[$name]) ? $this->keyBytes($columns[$name]['type'], $columns[$name]['collation'] ?? null) : null;

            if ($size === null) {
                return; // a column or a type we cannot size: leave it to the database
            }

            $bytes += $size;
        }

        if ($bytes > self::MYSQL_MAX_KEY_BYTES) {
            throw new RuntimeException(
                "The unique key of {$tokens} with purpose would take {$bytes} bytes, over InnoDB's "
                .self::MYSQL_MAX_KEY_BYTES.'-byte limit. Make provider varchar(50) (the '
                .'2025_01_01_000006_shorten_provider_columns migration does it; publish it if you run published '
                .'copies) or shorten provider_holder_id or ownable_type, then migrate again. Nothing was changed.'
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
     * Named like Laravel names its own indexes (table prefix, no schema dot),
     * and like 2025_01_01_000007 names its keys. A name over PostgreSQL's 63
     * characters (MySQL allows 64), from a long table name or prefix, is cut
     * and ends with a hash of the full name, so it stays distinct.
     */
    private function indexName(string $table, string $suffix): string
    {
        $connection = Schema::getConnection();

        if ($connection->getConfig('prefix_indexes')) {
            $prefix = $connection->getTablePrefix();
            $dot = strrpos($table, '.');
            $table = $dot === false ? $prefix.$table : substr_replace($table, '.'.$prefix, $dot, 1);
        }

        $name = str_replace(['-', '.'], '_', strtolower("{$table}_{$suffix}"));

        return strlen($name) <= 63 ? $name : substr($name, 0, 54).'_'.substr(md5($name), 0, 8);
    }
};
