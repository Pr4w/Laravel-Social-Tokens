<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('social-tokens.tokens_table', 'social_tokens'), function (Blueprint $table) {
            // Consecutive credential checks that came back terminal without being
            // definitive (a bare Meta 190). check-static flags on the second one.
            $table->unsignedSmallInteger('failed_checks')->default(0)->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table(config('social-tokens.tokens_table', 'social_tokens'), function (Blueprint $table) {
            $table->dropColumn('failed_checks');
        });
    }
};
