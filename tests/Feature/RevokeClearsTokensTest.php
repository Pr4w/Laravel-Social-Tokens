<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\CredentialRevoked;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Pr4w\SocialTokens\Tests\Fixtures\FakeConnector;
use Pr4w\SocialTokens\Tests\Fixtures\Owner;

/*
 * Revoking used to change the status column only: the access and refresh
 * tokens stayed in the row, decryptable with APP_KEY, for good. Facebook,
 * Instagram, Threads and LinkedIn have no provider-side revocation, and Google
 * and TikTok skip it while another owner holds a grant, so the stored copy was
 * often a working token the user had asked to remove. Disconnecting a user's
 * last Facebook Page also left the Meta user token behind it active, holding
 * a 60-day token that can mint Page tokens.
 */

beforeEach(fn () => FakeConnector::reset());

/** A credential holding every token column, with one account posting with it. */
function clearedCredential(string $provider = 'fake', ?Owner $owner = null, string $holder = 'holder-1'): SocialToken
{
    $token = SocialToken::persistCredential(['provider' => $provider, 'provider_holder_id' => $holder] + SocialAccount::ownerKey($owner), [
        'access_token' => "{$holder}-access",
        'refresh_token' => "{$holder}-refresh",
        'expires_at' => now()->addHour(),
        'refresh_expires_at' => now()->addYear(),
        'renew_at' => now()->addMinutes(30),
    ]);

    SocialAccount::create([
        'provider' => $provider,
        'provider_user_id' => $holder,
        'provider_holder_id' => $holder,
        'social_token_id' => $token->getKey(),
        'status' => AccountStatus::Active,
    ] + SocialAccount::ownerKey($owner));

    return $token;
}

function expectTokensCleared(SocialToken $token): void
{
    $row = DB::table('social_tokens')->where('id', $token->getKey())->first();

    expect($row->status)->toBe(AccountStatus::Revoked->value)
        ->and($row->access_token)->toBeNull()
        ->and($row->refresh_token)->toBeNull()
        ->and($row->expires_at)->toBeNull()
        ->and($row->refresh_expires_at)->toBeNull()
        ->and($row->renew_at)->toBeNull();
}

/** A Facebook user credential and its Pages (and Instagram accounts), as the fan-out actions store them. */
function clearedMetaLogin(?Owner $owner, array $pages, array $instagram = []): SocialToken
{
    $user = SocialToken::persistCredential(['provider' => 'facebook', 'provider_holder_id' => 'user-1'] + SocialAccount::ownerKey($owner), [
        'access_token' => 'meta-user-token',
        'expires_at' => now()->addDays(60),
        'renew_at' => now()->addDays(53),
    ]);

    foreach ($pages as $page) {
        $pageToken = SocialToken::persistCredential(['provider' => 'facebook', 'provider_holder_id' => $page] + SocialAccount::ownerKey($owner), [
            'access_token' => "{$page}-token",
            'renew_at' => null,
        ]);

        SocialAccount::create([
            'provider' => 'facebook', 'provider_user_id' => $page, 'provider_holder_id' => 'user-1',
            'social_token_id' => $pageToken->getKey(), 'status' => AccountStatus::Active,
        ] + SocialAccount::ownerKey($owner));
    }

    foreach ($instagram as $ig) {
        SocialAccount::create([
            'provider' => 'instagram', 'provider_user_id' => $ig, 'provider_holder_id' => 'user-1',
            'social_token_id' => $user->getKey(), 'status' => AccountStatus::Active,
        ] + SocialAccount::ownerKey($owner));
    }

    return $user;
}

function clearedAccount(string $id, ?Owner $owner = null): SocialAccount
{
    return SocialAccount::query()->ownedBy($owner)->where('provider_user_id', $id)->sole();
}

it('erases the tokens of a revoked credential, after handing them to the provider', function () {
    $seen = null;
    FakeConnector::$revokeUsing = function (SocialToken $token) use (&$seen) {
        $seen = [$token->access_token, $token->refresh_token];
    };
    $token = clearedCredential();

    app(SocialTokens::class)->revoke($token);

    expect($seen)->toBe(['holder-1-access', 'holder-1-refresh'])
        ->and($token->access_token)->toBeNull()
        ->and($token->refresh_token)->toBeNull()
        ->and($token->renew_at)->toBeNull()
        ->and($token->isDirty())->toBeFalse();

    expectTokensCleared($token);
});

it('erases the tokens in the same write as the status, so a listener sees them gone', function () {
    $seen = 'not called';
    Event::listen(CredentialRevoked::class, function (CredentialRevoked $event) use (&$seen) {
        $seen = DB::table('social_tokens')->where('id', $event->token->getKey())->value('access_token');
    });

    app(SocialTokens::class)->revoke(clearedCredential());

    expect($seen)->toBeNull();
});

it('erases the tokens while another owner still holds a grant and the provider is not called', function () {
    Http::fake();
    $alice = Owner::create(['name' => 'alice']);
    $bob = Owner::create(['name' => 'bob']);
    clearedCredential('google', $alice, 'g-1');
    $bobs = clearedCredential('google', $bob, 'g-1');

    app(SocialTokens::class)->disconnect($bobs->accounts()->sole());

    Http::assertNothingSent();
    expectTokensCleared($bobs);
});

it('erases the tokens of the credential a disconnect revokes, not of one still in use', function () {
    $token = clearedCredential();
    SocialAccount::create(['provider' => 'fake', 'provider_user_id' => 'sibling', 'social_token_id' => $token->getKey(), 'status' => AccountStatus::Active]);

    app(SocialTokens::class)->disconnect(clearedAccount('holder-1'));

    expect($token->fresh()->access_token)->toBe('holder-1-access');

    app(SocialTokens::class)->disconnect(clearedAccount('sibling'));

    expectTokensCleared($token);
});

it('erases the tokens of the credentials a provider deauthorization revokes', function () {
    $user = clearedMetaLogin(null, ['page-1'], ['ig-1']);

    app(SocialTokens::class)->revokeExternalUser('facebook', 'user-1');

    expectTokensCleared($user);
    expectTokensCleared(clearedAccount('page-1')->credential);
});

it('revokes the Meta user credential behind the last Facebook Page disconnected', function () {
    $owner = Owner::create();
    $user = clearedMetaLogin($owner, ['page-1', 'page-2']);

    app(SocialTokens::class)->disconnect(clearedAccount('page-1', $owner));

    expect($user->fresh()->status)->toBe(AccountStatus::Active);

    app(SocialTokens::class)->disconnect(clearedAccount('page-2', $owner));

    expectTokensCleared($user);
    expectTokensCleared(clearedAccount('page-2', $owner)->credential);
});

it('keeps the Meta user credential while an Instagram account of the same user posts with it', function () {
    $user = clearedMetaLogin(null, ['page-1'], ['ig-1']);

    app(SocialTokens::class)->disconnect(clearedAccount('page-1'));

    expect($user->fresh()->status)->toBe(AccountStatus::Active);

    app(SocialTokens::class)->disconnect(clearedAccount('ig-1'));

    expectTokensCleared($user);
});

it('keeps the Meta user credential while a Facebook Page of the same user is connected', function () {
    $user = clearedMetaLogin(null, ['page-1'], ['ig-1']);

    app(SocialTokens::class)->disconnect(clearedAccount('ig-1'));

    expect($user->fresh()->status)->toBe(AccountStatus::Active)
        ->and($user->fresh()->access_token)->toBe('meta-user-token');
});

it('keeps the Meta user credential while a Page of the user is only flagged, as a disconnect does for siblings', function () {
    $user = clearedMetaLogin(null, ['page-1', 'page-2']);
    clearedAccount('page-1')->markNeedsReconnect('Page no longer managed by the connected Facebook user.');

    app(SocialTokens::class)->disconnect(clearedAccount('page-2'));

    expect($user->fresh()->status)->toBe(AccountStatus::Active);
});

it('revokes the Meta user credential behind the last Page that still has a credential', function () {
    $user = clearedMetaLogin(null, ['page-1', 'page-2']);

    // Page 1's token was flagged and nobody reconnected: prune deletes it.
    clearedAccount('page-1')->credential->markNeedsReconnect('Page token rejected.');
    $this->travel(31)->days();
    Artisan::call('social-tokens:prune');

    expect(clearedAccount('page-1')->social_token_id)->toBeNull()
        ->and(SocialToken::query()->inUse()->whereKey($user->getKey())->exists())->toBeTrue();

    app(SocialTokens::class)->disconnect(clearedAccount('page-2'));

    expectTokensCleared($user);
    expect(SocialToken::query()->inUse()->whereKey($user->getKey())->exists())->toBeFalse();
});

it('leaves another owner\'s Meta user credential alone when the last Page of one owner goes', function () {
    $alice = Owner::create(['name' => 'alice']);
    $bob = Owner::create(['name' => 'bob']);
    $alices = clearedMetaLogin($alice, ['page-1']);
    $bobs = clearedMetaLogin($bob, ['page-1']);

    app(SocialTokens::class)->disconnect(clearedAccount('page-1', $alice));

    expectTokensCleared($alices);
    expect($bobs->fresh()->status)->toBe(AccountStatus::Active)
        ->and($bobs->fresh()->access_token)->toBe('meta-user-token');
});

it('logs a revocation the provider does not confirm', function (string $provider, string $url, array $body, int $status) {
    Http::fake([$url => Http::response($body, $status)]);
    Log::spy();

    app(ConnectorRegistry::class)->for($provider)->revoke(clearedCredential($provider));

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === "[social-tokens] {$provider} did not confirm the token revocation"
            && $context['provider'] === $provider
            && $context['status'] === $status
            && ! str_contains(json_encode($context), 'holder-1'))
        ->once();
})->with([
    'google refused' => ['google', 'oauth2.googleapis.com/revoke', ['error' => 'invalid_token', 'error_description' => 'Token expired or revoked'], 400],
    'tiktok refused' => ['tiktok', 'open.tiktokapis.com/*', ['error' => 'invalid_request', 'error_description' => 'bad token'], 400],
    'tiktok error in a 200' => ['tiktok', 'open.tiktokapis.com/*', ['error' => 'invalid_request', 'error_description' => 'bad token'], 200],
]);

it('logs nothing when the provider confirms the revocation', function (string $provider, string $url) {
    Http::fake([$url => Http::response('')]);
    Log::spy();

    app(ConnectorRegistry::class)->for($provider)->revoke(clearedCredential($provider));

    Log::shouldNotHaveReceived('warning');
})->with([
    ['google', 'oauth2.googleapis.com/revoke'],
    ['tiktok', 'open.tiktokapis.com/*'],
]);

it('clears the tokens 2.x kept on revoked credentials, and only theirs', function () {
    $revoked = clearedCredential('fake', null, 'old');
    $flagged = clearedCredential('fake', null, 'flagged');
    $active = clearedCredential('fake', null, 'live');

    // Revoked by 2.x: the status changed, the tokens stayed.
    DB::table('social_tokens')->where('id', $revoked->getKey())->update(['status' => AccountStatus::Revoked->value]);
    DB::table('social_tokens')->where('id', $flagged->getKey())->update(['status' => AccountStatus::NeedsReconnect->value]);
    $updatedAt = DB::table('social_tokens')->where('id', $revoked->getKey())->value('updated_at');

    $this->travel(5)->minutes();

    $migration = include __DIR__.'/../../database/migrations/2025_01_01_000010_clear_tokens_of_revoked_credentials.php';
    $migration->up();
    $migration->up(); // idempotent

    expectTokensCleared($revoked);
    expect(DB::table('social_tokens')->where('id', $revoked->getKey())->value('updated_at'))->toBe($updatedAt)
        ->and($flagged->fresh()->access_token)->toBe('flagged-access')
        ->and($active->fresh()->refresh_token)->toBe('live-refresh');
});
