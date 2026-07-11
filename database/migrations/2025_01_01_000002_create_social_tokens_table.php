<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('social-tokens.tokens_table', 'social_tokens'), function (Blueprint $table) {
            $table->id();

            // The connector that REFRESHES this credential (not always the account
            // provider): facebook for Meta (Facebook + Instagram share one user
            // token), threads, tiktok, google, linkedin.
            $table->string('provider');

            // The external identity that owns the credential: the Facebook user id,
            // the LinkedIn member id, or — for 1:1 providers — the account's own id.
            $table->string('provider_holder_id')->nullable();

            // The renewable credential. Encrypted at rest via model casts.
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('refresh_expires_at')->nullable();
            $table->timestamp('renew_at')->nullable();

            $table->json('scopes')->nullable(); // token-level granted scopes

            $table->string('status')->default('active'); // active | needs_reconnect | revoked
            $table->timestamp('last_renewed_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();

            // One credential per (refresher, holder).
            $table->unique(['provider', 'provider_holder_id']);
            $table->index(['status', 'renew_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('social-tokens.tokens_table', 'social_tokens'));
    }
};
