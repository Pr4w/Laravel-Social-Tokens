<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
 * MySQL: the new unique keys fit InnoDB's 3072-byte limit with the default
 * bigint morphs, not with uuid/ulid morph ids.
 */
return new class extends Migration
{
    public function up(): void
    {
        $accounts = config('social-tokens.table', 'social_accounts');
        $tokens = config('social-tokens.tokens_table', 'social_tokens');

        // The old global key goes first: splitting a shared credential inserts
        // a second row with the same (provider, provider_holder_id).
        Schema::table($tokens, function (Blueprint $table) {
            $table->nullableMorphs('ownable');
            $table->dropUnique(['provider', 'provider_holder_id']);
        });

        foreach (DB::table($tokens)->orderBy('id')->pluck('id') as $tokenId) {
            $this->assignOwners($accounts, $tokens, (int) $tokenId);
        }

        Schema::table($tokens, function (Blueprint $table) use ($tokens) {
            $table->unique(['provider', 'provider_holder_id', 'ownable_type', 'ownable_id'], "{$tokens}_owner_identity_unique");
        });

        Schema::table($accounts, function (Blueprint $table) use ($accounts) {
            $table->dropUnique(['provider', 'provider_user_id']);
            $table->unique(['provider', 'provider_user_id', 'ownable_type', 'ownable_id'], "{$accounts}_owner_identity_unique");
        });
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

        Schema::table($accounts, function (Blueprint $table) use ($accounts) {
            $table->dropUnique("{$accounts}_owner_identity_unique");
            $table->unique(['provider', 'provider_user_id']);
        });

        Schema::table($tokens, function (Blueprint $table) use ($tokens) {
            $table->dropUnique("{$tokens}_owner_identity_unique");
            $table->unique(['provider', 'provider_holder_id']);
            $table->dropMorphs('ownable');
        });
    }

    /**
     * Give a credential its accounts' owner, copying it for every extra owner.
     * Raw rows only: the encrypted columns are copied as stored, never decrypted.
     */
    private function assignOwners(string $accounts, string $tokens, int $tokenId): void
    {
        $token = DB::table($tokens)->where('id', $tokenId)->first();

        if ($token === null) {
            return;
        }

        // Accounts posting with it; failing that, accounts naming it as their
        // holder (the Meta user credential behind a user's Facebook Pages).
        $byToken = DB::table($accounts)->where('social_token_id', $tokenId);
        $viaHolder = ! $byToken->clone()->exists();

        $source = $viaHolder
            ? DB::table($accounts)->where('provider', $token->provider)->where('provider_holder_id', $token->provider_holder_id)
            : $byToken;

        $owners = $source->clone()
            ->select('ownable_type', 'ownable_id')
            ->distinct()
            ->orderBy('ownable_type')
            ->orderBy('ownable_id')
            ->get();

        if ($owners->isEmpty()) {
            return; // backs no account: stays owner-less
        }

        foreach ($owners as $index => $owner) {
            if ($index === 0) {
                DB::table($tokens)->where('id', $tokenId)->update([
                    'ownable_type' => $owner->ownable_type,
                    'ownable_id' => $owner->ownable_id,
                ]);

                continue;
            }

            $copy = (array) $token;
            unset($copy['id']);
            $copy['ownable_type'] = $owner->ownable_type;
            $copy['ownable_id'] = $owner->ownable_id;

            $copyId = DB::table($tokens)->insertGetId($copy);

            if (! $viaHolder) {
                $this->whereOwner(DB::table($accounts)->where('social_token_id', $tokenId), $owner)
                    ->update(['social_token_id' => $copyId]);
            }
        }
    }

    private function whereOwner(Builder $query, object $owner): Builder
    {
        return $owner->ownable_type === null
            ? $query->whereNull('ownable_type')->whereNull('ownable_id')
            : $query->where('ownable_type', $owner->ownable_type)->where('ownable_id', $owner->ownable_id);
    }
};
