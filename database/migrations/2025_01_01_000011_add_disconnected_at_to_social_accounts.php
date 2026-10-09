<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3.0: when the user disconnected an account (SocialTokens::disconnect()).
 * A revoked account with this date stays out of the fan-out reconnects of
 * its login (StoreFacebookPages, StoreInstagramAccounts,
 * StoreLinkedInOrganizations), which list every target the provider grants;
 * one revoked by the provider's own signal (a deauthorization) has none, and
 * a reconnect brings it back.
 *
 * Added only when missing, so a run that stopped resumes. No index: it is
 * only read on rows already found by their keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        $accounts = config('social-tokens.table', 'social_accounts');

        // Under `migrate --pretend`, list the statement without reading the schema.
        if (Schema::getConnection()->pretending() || ! Schema::hasColumn($accounts, 'disconnected_at')) {
            Schema::table($accounts, fn (Blueprint $table) => $table->timestamp('disconnected_at')->nullable());
        }
    }

    public function down(): void
    {
        $accounts = config('social-tokens.table', 'social_accounts');

        if (Schema::getConnection()->pretending() || Schema::hasColumn($accounts, 'disconnected_at')) {
            Schema::table($accounts, fn (Blueprint $table) => $table->dropColumn('disconnected_at'));
        }
    }
};
