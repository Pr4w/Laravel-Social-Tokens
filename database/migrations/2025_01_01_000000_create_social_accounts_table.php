<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('social-tokens.table', 'social_accounts'), function (Blueprint $table) {
            $table->id();

            // The app-side entity that owns this connection (User, Team, Workspace...).
            // Nullable so an account can be connected before it is attached to anyone.
            $table->nullableMorphs('ownable');

            // Optional: who performed the connection (useful when ownable is a Team).
            $table->nullableMorphs('connected_by');

            $table->string('provider');                 // tiktok, google, instagram, linkedin
            $table->string('provider_user_id')->nullable(); // external account id (disambiguates multiple accounts)

            // Denormalised profile snapshot, mapped from the Socialite User contract.
            $table->string('name')->nullable();
            $table->string('nickname')->nullable();
            $table->string('email')->nullable();
            $table->text('avatar')->nullable();

            // Credentials. Encrypted at rest via model casts.
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();

            $table->timestamp('expires_at')->nullable();          // access token expiry
            $table->timestamp('refresh_expires_at')->nullable();  // refresh token expiry (if any)
            $table->timestamp('renew_at')->nullable();            // expires_at minus connector lead time

            $table->json('scopes')->nullable();   // granted scopes
            $table->json('profile')->nullable();  // provider specific extras (open_id, bio, etc.)

            $table->string('status')->default('active'); // active | expiring | needs_reconnect | revoked
            $table->timestamp('last_renewed_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            $table->index(['status', 'renew_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('social-tokens.table', 'social_accounts'));
    }
};
