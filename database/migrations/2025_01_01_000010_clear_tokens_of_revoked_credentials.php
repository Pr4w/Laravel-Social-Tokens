<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * 3.0: revoking a credential erases its tokens (SocialToken::markRevoked()).
 * Credentials revoked before kept them, still decryptable with APP_KEY and
 * often still valid at the provider: erase them too. Nothing reads a revoked
 * credential's tokens, and a reconnect writes new ones.
 *
 * One statement, which only matches rows that still hold something, so it can
 * run again. updated_at is left as it is: social-tokens:prune counts its
 * retention from the revocation. Nothing to roll back: the tokens are gone.
 * The status is a literal, not a binding: `migrate --pretend` quotes string
 * bindings through the connection, which it never opens.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table(config('social-tokens.tokens_table', 'social_tokens'))
            ->where('status', new Expression("'revoked'"))
            ->where(fn ($query) => $query
                ->whereNotNull('access_token')
                ->orWhereNotNull('refresh_token')
                ->orWhereNotNull('expires_at')
                ->orWhereNotNull('refresh_expires_at')
                ->orWhereNotNull('renew_at'))
            ->update([
                'access_token' => null,
                'refresh_token' => null,
                'expires_at' => null,
                'refresh_expires_at' => null,
                'renew_at' => null,
            ]);
    }

    public function down(): void
    {
        // The erased tokens cannot be restored.
    }
};
