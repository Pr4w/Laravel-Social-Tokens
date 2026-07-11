<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;

// The token columns are dropped by the normal migration flow; put them back so
// we can seed legacy rows and exercise the backfill against them.
beforeEach(function () {
    Schema::table('social_accounts', function (Blueprint $table) {
        $table->text('access_token')->nullable();
        $table->text('refresh_token')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamp('refresh_expires_at')->nullable();
        $table->timestamp('renew_at')->nullable();
    });
});

function insertLegacyAccount(array $attrs): int
{
    return DB::table('social_accounts')->insertGetId(array_merge([
        'provider' => 'tiktok',
        'provider_user_id' => 'x-'.uniqid(),
        'status' => 'active',
        'social_token_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attrs));
}

function runBackfill(): void
{
    (include __DIR__.'/../../database/migrations/2025_01_01_000004_backfill_social_tokens.php')->up();
}

it('moves a 1:1 provider token into a renewable credential and repoints the account', function () {
    $id = insertLegacyAccount([
        'provider' => 'tiktok', 'provider_user_id' => 'tt-1',
        'access_token' => Crypt::encryptString('tt-access'),
        'refresh_token' => Crypt::encryptString('tt-refresh'),
        'expires_at' => now()->addDay(), 'renew_at' => now()->addHours(22),
    ]);

    runBackfill();

    $credential = SocialAccount::find($id)->credential;

    expect($credential)->not->toBeNull()
        ->and($credential->provider)->toBe('tiktok')
        ->and($credential->provider_holder_id)->toBe('tt-1')
        ->and($credential->access_token)->toBe('tt-access')     // decrypt -> re-encrypt round trips
        ->and($credential->refresh_token)->toBe('tt-refresh')
        ->and($credential->renew_at)->not->toBeNull();
});

it('turns each Facebook page token into a static credential', function () {
    $id = insertLegacyAccount([
        'provider' => 'facebook', 'provider_user_id' => 'page-1', 'provider_holder_id' => 'fb-user',
        'access_token' => Crypt::encryptString('page-token'),
        'refresh_token' => Crypt::encryptString('user-token'),
        'expires_at' => now()->addDay(), 'renew_at' => now()->addDay(),
    ]);

    runBackfill();

    $credential = SocialAccount::find($id)->credential;

    expect($credential->provider)->toBe('facebook')
        ->and($credential->provider_holder_id)->toBe('page-1')  // holder = page id
        ->and($credential->access_token)->toBe('page-token')
        ->and($credential->renew_at)->toBeNull()                // static
        ->and($credential->expires_at)->toBeNull();
});

it('shares one renewable Meta credential across a user\'s Instagram accounts', function () {
    $a = insertLegacyAccount([
        'provider' => 'instagram', 'provider_user_id' => 'ig-1', 'provider_holder_id' => 'fb-user',
        'access_token' => Crypt::encryptString('user-token'),
        'expires_at' => now()->addDays(60), 'renew_at' => now()->addDays(53),
    ]);
    $b = insertLegacyAccount([
        'provider' => 'instagram', 'provider_user_id' => 'ig-2', 'provider_holder_id' => 'fb-user',
        'access_token' => Crypt::encryptString('user-token'),
        'expires_at' => now()->addDays(60), 'renew_at' => now()->addDays(53),
    ]);

    runBackfill();

    $credA = SocialAccount::find($a)->credential;

    expect(SocialAccount::find($b)->credential->is($credA))->toBeTrue() // shared
        ->and($credA->provider)->toBe('facebook')
        ->and($credA->provider_holder_id)->toBe('fb-user')
        ->and($credA->access_token)->toBe('user-token')
        ->and($credA->renew_at)->not->toBeNull()                        // renewable
        ->and(SocialToken::where('provider_holder_id', 'fb-user')->count())->toBe(1);
});

it('skips accounts that are already linked', function () {
    $token = SocialToken::create(['provider' => 'tiktok', 'provider_holder_id' => 'existing']);
    $id = insertLegacyAccount([
        'provider' => 'tiktok', 'provider_user_id' => 'tt-9',
        'social_token_id' => $token->getKey(),
        'access_token' => Crypt::encryptString('x'),
    ]);

    runBackfill();

    expect(SocialAccount::find($id)->social_token_id)->toBe($token->getKey())
        ->and(SocialToken::count())->toBe(1); // no new credential created
});
