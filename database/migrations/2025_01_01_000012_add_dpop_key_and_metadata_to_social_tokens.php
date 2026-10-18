<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3.3: Bluesky (AT Protocol OAuth) binds its tokens to a DPoP key pair made
 * for each connection, and each account has its own authorization server.
 * The credential keeps the private key (dpop_key, encrypted like the tokens)
 * and the server's endpoints and the account's PDS (metadata). Both null on
 * every other credential.
 *
 * Each column is added only when missing, so a run that stopped halfway
 * resumes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tokens = config('social-tokens.tokens_table', 'social_tokens');
        $pretending = Schema::getConnection()->pretending(); // lists the statements without reading the schema

        if ($pretending || ! Schema::hasColumn($tokens, 'dpop_key')) {
            Schema::table($tokens, fn (Blueprint $table) => $table->text('dpop_key')->nullable());
        }

        if ($pretending || ! Schema::hasColumn($tokens, 'metadata')) {
            Schema::table($tokens, fn (Blueprint $table) => $table->json('metadata')->nullable());
        }
    }

    public function down(): void
    {
        $tokens = config('social-tokens.tokens_table', 'social_tokens');
        $pretending = Schema::getConnection()->pretending();

        foreach (['metadata', 'dpop_key'] as $column) {
            if ($pretending || Schema::hasColumn($tokens, $column)) {
                Schema::table($tokens, fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
