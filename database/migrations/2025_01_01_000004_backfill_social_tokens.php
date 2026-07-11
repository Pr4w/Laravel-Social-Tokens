<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Models\SocialToken;

/**
 * Group existing accounts into credentials and repoint them. Runs before the
 * token columns are dropped from social_accounts. Reads the (encrypted) account
 * tokens raw and decrypts by hand, so it does not depend on the model casts,
 * which no longer describe those columns.
 *
 *  - instagram : one shared renewable Meta credential per Facebook user
 *  - facebook  : one static page-token credential per page (renew_at null)
 *  - others    : one renewable credential per account (1:1)
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('social-tokens.table', 'social_accounts');

        DB::table($table)->whereNull('social_token_id')->get()->each(function (object $row) use ($table) {
            [$provider, $holder, $attributes] = $this->credentialFor($row);

            if ($holder === null) {
                return; // cannot group without a holder; leave it for a reconnect
            }

            $token = SocialToken::query()->updateOrCreate(
                ['provider' => $provider, 'provider_holder_id' => (string) $holder],
                $attributes,
            );

            DB::table($table)->where('id', $row->id)->update(['social_token_id' => $token->getKey()]);
        });
    }

    public function down(): void
    {
        $table = config('social-tokens.table', 'social_accounts');

        DB::table($table)->update(['social_token_id' => null]);
        SocialToken::query()->delete();
    }

    /**
     * @return array{0: string, 1: ?string, 2: array<string, mixed>}
     */
    private function credentialFor(object $row): array
    {
        $status = AccountStatus::tryFrom($row->status ?? 'active') ?? AccountStatus::Active;
        $access = $this->decrypt($row->access_token ?? null);
        $refresh = $this->decrypt($row->refresh_token ?? null);

        if ($row->provider === 'instagram') {
            // Shared renewable Meta user credential (the access token IS the user token).
            return ['facebook', $row->provider_holder_id ?? $row->provider_user_id, [
                'access_token' => $access,
                'refresh_token' => null,
                'expires_at' => $row->expires_at ?? null,
                'renew_at' => $row->renew_at ?? null,
                'status' => $status,
            ]];
        }

        if ($row->provider === 'facebook') {
            // Static page-token credential (the access token IS the page token).
            return ['facebook', $row->provider_user_id, [
                'access_token' => $access,
                'refresh_token' => null,
                'expires_at' => null,
                'renew_at' => null,
                'status' => $status,
            ]];
        }

        // 1:1 provider — a renewable credential holding the account's own tokens.
        return [$row->provider, $row->provider_user_id, [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'expires_at' => $row->expires_at ?? null,
            'refresh_expires_at' => $row->refresh_expires_at ?? null,
            'renew_at' => $row->renew_at ?? null,
            'status' => $status,
        ]];
    }

    private function decrypt(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            return $value; // already plain / not encrypted
        }
    }
};
