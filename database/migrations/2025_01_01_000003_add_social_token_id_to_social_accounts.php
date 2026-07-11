<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tokens = config('social-tokens.tokens_table', 'social_tokens');

        Schema::table(config('social-tokens.table', 'social_accounts'), function (Blueprint $table) use ($tokens) {
            // The credential that backs this account. Nullable so accounts can be
            // backfilled/attached progressively; null when the credential is gone.
            $table->foreignId('social_token_id')
                ->nullable()
                ->constrained($tokens)
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table(config('social-tokens.table', 'social_accounts'), function (Blueprint $table) {
            $table->dropConstrainedForeignId('social_token_id');
        });
    }
};
