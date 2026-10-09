<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\CredentialNeedsReconnect;
use Pr4w\SocialTokens\Events\CredentialRenewed;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\RenewalResult;
use Pr4w\SocialTokens\Tests\Fixtures\FakeConnector;
use Pr4w\SocialTokens\Tests\Fixtures\Owner;

function socialToken(array $attrs = []): SocialToken
{
    return SocialToken::create(array_merge([
        'provider' => 'facebook',
        'provider_holder_id' => 'h-'.uniqid(),
        'access_token' => 'cred-token',
        'status' => AccountStatus::Active,
    ], $attrs));
}

it('uses the configured tokens table', function () {
    expect((new SocialToken)->getTable())->toBe('social_tokens');
});

it('backs many accounts and each account belongs to it', function () {
    $token = socialToken();
    $a = SocialAccount::create(['provider' => 'facebook', 'provider_user_id' => 'p1', 'social_token_id' => $token->id, 'status' => AccountStatus::Active]);
    $b = SocialAccount::create(['provider' => 'instagram', 'provider_user_id' => 'ig1', 'social_token_id' => $token->id, 'status' => AccountStatus::Active]);

    expect($token->accounts)->toHaveCount(2)
        ->and($a->credential->is($token))->toBeTrue()
        ->and($b->credential->is($token))->toBeTrue();
});

it('detects expired access and refresh tokens', function () {
    expect(socialToken(['expires_at' => now()->subMinute()])->isAccessTokenExpired())->toBeTrue()
        ->and(socialToken(['expires_at' => now()->addHour()])->isAccessTokenExpired())->toBeFalse()
        ->and(socialToken(['refresh_expires_at' => now()->subDay()])->isRefreshTokenExpired())->toBeTrue();
});

it('knows when its renewal window has opened, independently of expiry', function () {
    expect(socialToken(['expires_at' => now()->addDays(6), 'renew_at' => now()->subMinute()])->isDueForRenewal())->toBeTrue()
        ->and(socialToken(['expires_at' => now()->addDays(60), 'renew_at' => now()->addDays(53)])->isDueForRenewal())->toBeFalse()
        ->and(socialToken(['expires_at' => null, 'renew_at' => null])->isDueForRenewal())->toBeFalse(); // static
});

it('scopes credentials due for renewal', function () {
    socialToken(['provider_holder_id' => 'due', 'renew_at' => now()->subMinute()]);
    socialToken(['provider_holder_id' => 'later', 'renew_at' => now()->addHour()]);
    socialToken(['provider_holder_id' => 'revoked', 'status' => AccountStatus::NeedsReconnect, 'renew_at' => now()->subDay()]);

    expect(SocialToken::query()->dueForRenewal()->pluck('provider_holder_id')->all())->toBe(['due']);
});

it('applies a renewal, recomputes the window, and fires CredentialRenewed', function () {
    Event::fake([CredentialRenewed::class]);
    FakeConnector::reset();
    $token = socialToken();

    $token->applyRenewal(
        RenewalResult::success(accessToken: 'fresh', expiresAt: now()->addHour(), refreshToken: 'new-refresh'),
        new FakeConnector, // 15 minute lead time
    );

    expect($token->access_token)->toBe('fresh')
        ->and($token->refresh_token)->toBe('new-refresh')
        ->and($token->status)->toBe(AccountStatus::Active)
        ->and($token->renew_at->timestamp)->toEqualWithDelta(now()->addHour()->subMinutes(15)->timestamp, 2);

    Event::assertDispatched(CredentialRenewed::class, fn ($e) => $e->token->is($token));
});

it('keeps a credential renewable when the provider omits the expiry', function () {
    Log::spy();
    $token = socialToken(['expires_at' => now()->addDay(), 'renew_at' => now()->subMinute()]);

    $token->applyRenewal(RenewalResult::success(accessToken: 'fresh'), new FakeConnector); // 15 minute lead time

    expect($token->access_token)->toBe('fresh')
        ->and($token->expires_at)->toBeNull() // unknown, not the stale previous expiry
        ->and($token->renew_at)->not->toBeNull() // still scheduled, never silently dropped
        ->and($token->renew_at->timestamp)->toEqualWithDelta(now()->addMinutes(15)->timestamp, 2);

    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'no expiry'))->once();
});

it('updates the credential scopes when the provider returns them', function (string $scope, array $expected) {
    $token = socialToken(['scopes' => ['old.scope']]);

    $token->applyRenewal(
        RenewalResult::success(accessToken: 'fresh', expiresAt: now()->addHour(), profile: ['scope' => $scope]),
        new FakeConnector,
    );

    expect($token->fresh()->scopes)->toBe($expected);
})->with([
    'comma separated (TikTok, LinkedIn)' => ['user.info.basic,video.publish', ['user.info.basic', 'video.publish']],
    'space separated (Google)' => ['openid https://www.googleapis.com/auth/youtube.upload', ['openid', 'https://www.googleapis.com/auth/youtube.upload']],
]);

it('keeps the credential scopes when the provider does not return them', function () {
    $token = socialToken(['scopes' => ['kept']]);

    $token->applyRenewal(RenewalResult::success(accessToken: 'fresh', expiresAt: now()->addHour()), new FakeConnector);

    expect($token->fresh()->scopes)->toBe(['kept']);
});

it('marks a credential for reconnection and fires CredentialNeedsReconnect', function () {
    Event::fake([CredentialNeedsReconnect::class]);
    $token = socialToken();

    $token->markNeedsReconnect('revoked upstream');

    expect($token->fresh()->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($token->fresh()->last_error)->toBe('revoked upstream');

    Event::assertDispatched(CredentialNeedsReconnect::class, fn ($e) => $e->reason === 'revoked upstream');
});

it('persists a credential under its keys, creating then updating the one row', function () {
    $owner = Owner::create();
    $keys = ['provider' => 'tiktok', 'provider_holder_id' => 'tt-1'] + SocialAccount::ownerKey($owner);

    $created = SocialToken::persistCredential($keys, ['access_token' => 'first', 'status' => AccountStatus::Active]);
    $updated = SocialToken::persistCredential($keys, ['access_token' => 'second', 'refresh_token' => 'r']);

    expect($updated->is($created))->toBeTrue()
        ->and(SocialToken::query()->count())->toBe(1)
        ->and($created->fresh()->access_token)->toBe('second')
        ->and($created->fresh()->refresh_token)->toBe('r')
        ->and($created->fresh()->ownable->is($owner))->toBeTrue();
});

it('updates the row a concurrent request created under the same keys', function (bool $inTransaction) {
    $owner = Owner::create();
    $keys = ['provider' => 'tiktok', 'provider_holder_id' => 'tt-1'] + SocialAccount::ownerKey($owner);

    // Another request inserts the row right after the lookup found none.
    $raced = false;
    DB::listen(function (QueryExecuted $query) use (&$raced, $keys) {
        if (! $raced && str_starts_with($query->sql, 'select')) {
            $raced = true;
            DB::table('social_tokens')->insert($keys + ['access_token' => encrypt('theirs', false), 'status' => 'active']);
        }
    });

    $persist = fn () => SocialToken::persistCredential($keys, ['access_token' => 'mine', 'status' => AccountStatus::Active]);
    $token = $inTransaction ? DB::transaction($persist) : $persist();

    expect($raced)->toBeTrue()
        ->and($token->exists)->toBeTrue()
        ->and(SocialToken::query()->count())->toBe(1)
        ->and(SocialToken::query()->sole()->access_token)->toBe('mine');
})->with(['outside a transaction' => false, 'inside a transaction' => true]);
