<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('social-tokens.table', 'social_accounts'), function (Blueprint $table) {
            // External id of the credential HOLDER, distinct from provider_user_id
            // where a row is not itself the user. For Facebook this is the user id
            // behind a set of page rows, so a reconnect can tell precisely which
            // pages that user no longer manages. Null for providers where the row
            // already is the user.
            $table->string('provider_holder_id')->nullable();

            $table->index(['provider', 'provider_holder_id']);
        });
    }

    public function down(): void
    {
        Schema::table(config('social-tokens.table', 'social_accounts'), function (Blueprint $table) {
            $table->dropIndex(['provider', 'provider_holder_id']);
            $table->dropColumn('provider_holder_id');
        });
    }
};
