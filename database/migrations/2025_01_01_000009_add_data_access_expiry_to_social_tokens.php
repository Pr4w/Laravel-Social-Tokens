<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3.0: Meta gives a Facebook Login user token two clocks, the token's own
 * expiry and its data access, which lapses 90 days after the user was last
 * active and only comes back with a new login. debug_token reports the
 * second; the package stores it here (data_access_expires_at) and warns once
 * ahead of it (data_access_warned_at). Both null on every other credential.
 *
 * Each column is added only when missing, so a run that stopped halfway
 * resumes. No index: the warning scan runs over few credentials.
 */
return new class extends Migration
{
    private const COLUMNS = ['data_access_expires_at', 'data_access_warned_at'];

    public function up(): void
    {
        $tokens = config('social-tokens.tokens_table', 'social_tokens');
        $pretending = Schema::getConnection()->pretending(); // lists the statements without reading the schema

        foreach (self::COLUMNS as $column) {
            if ($pretending || ! Schema::hasColumn($tokens, $column)) {
                Schema::table($tokens, fn (Blueprint $table) => $table->timestamp($column)->nullable());
            }
        }
    }

    public function down(): void
    {
        $tokens = config('social-tokens.tokens_table', 'social_tokens');
        $pretending = Schema::getConnection()->pretending();

        foreach (array_reverse(self::COLUMNS) as $column) {
            if ($pretending || Schema::hasColumn($tokens, $column)) {
                Schema::table($tokens, fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
