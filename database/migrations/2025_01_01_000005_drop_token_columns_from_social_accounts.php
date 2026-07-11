<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The renewal columns moved to social_tokens (see the backfill migration). Drop
 * them from social_accounts, which now only points at its credential.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('social-tokens.table', 'social_accounts'), function (Blueprint $table) {
            $table->dropIndex(['status', 'renew_at']); // references renew_at
            $table->dropColumn([
                'access_token',
                'refresh_token',
                'expires_at',
                'refresh_expires_at',
                'renew_at',
                'last_renewed_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table(config('social-tokens.table', 'social_accounts'), function (Blueprint $table) {
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('refresh_expires_at')->nullable();
            $table->timestamp('renew_at')->nullable();
            $table->timestamp('last_renewed_at')->nullable();

            $table->index(['status', 'renew_at']);
        });
    }
};
