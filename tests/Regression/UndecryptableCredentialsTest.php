<?php

use Illuminate\Encryption\Encrypter;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Actions\StoreConnection;
use Pr4w\SocialTokens\Actions\StoreInstagramAccounts;
use Pr4w\SocialTokens\Actions\StoreLinkedInOrganizations;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Events\CredentialNeedsReconnect;
use Pr4w\SocialTokens\Exceptions\NeedsReconnectException;
use Pr4w\SocialTokens\Exceptions\TransientRenewalException;
use Pr4w\SocialTokens\Jobs\RenewCredential;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;

/*
 * A credential written under an APP_KEY that is gone (not in
 * APP_PREVIOUS_KEYS) no longer decrypts. social-tokens:reencrypt, and the
 * README, tell the operator to let those users reconnect. But the connect
 * paths decrypted the stored row before writing over it (to keep a known
 * refresh token, to compare a re-synced token, and in Eloquent's dirty check
 * without previous keys), so every reconnect threw DecryptException and
 * changed nothing. And each renewal and publish threw DecryptException out of
 * the package and into the error tracker. A key problem is the operator's: it
 * is now retried and alerted (Log::critical), never flagged on its own, and
 * the operator flags the truly lost credentials with
 * social-tokens:reencrypt --flag-undecryptable.
 */

/** Write the credential's tokens as ciphertext of a key the app no longer has. */
function encryptedUnderLostKey(SocialToken $token): SocialToken
{
    $lost = new Encrypter(random_bytes(32), 'aes-256-cbc');

    DB::table($token->getTable())->where('id', $token->getKey())->update(array_filter([
        'access_token' => $token->getAttributes()['access_token'] === null ? null : $lost->encryptString('old-access'),
        'refresh_token' => $token->getAttributes()['refresh_token'] === null ? null : $lost->encryptString('old-refresh'),
    ], fn ($value) => $value !== null));

    return $token->fresh();
}

/** APP_PREVIOUS_KEYS set (to a key that does not decrypt these tokens either), or not. */
function usePreviousKeys(bool $withPreviousKeys): void
{
    config(['app.previous_keys' => $withPreviousKeys ? ['base64:'.base64_encode(random_bytes(32))] : []]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
}

function fakeUndecryptableMeta(): void
{
    Http::fake(function ($request) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/oauth/access_token') => Http::response(['access_token' => 'new-user-token', 'expires_in' => 5184000]),
            str_contains($url, '/debug_token') => Http::response(['data' => ['expires_at' => now()->addDays(60)->getTimestamp(), 'scopes' => [], 'granular_scopes' => []]]),
            str_contains($url, '/me/accounts') => Http::response(['data' => [[
                'id' => 'page-1', 'name' => 'Page One', 'access_token' => 'new-page-token',
                'instagram_business_account' => ['id' => 'ig-1', 'username' => 'insta'],
            ]]]),
            default => Http::response([], 404),
        };
    });
}

dataset('previous keys', ['without APP_PREVIOUS_KEYS' => false, 'with APP_PREVIOUS_KEYS' => true]);

// Reconnecting ------------------------------------------------------------------

it('lets the user reconnect an account whose stored tokens no longer decrypt', function (string $provider, ?string $newRefreshToken, bool $withPreviousKeys) {
    Http::preventStrayRequests();
    $account = app(StoreConnection::class)->handle($provider, socialiteUser(['id' => 'u-1', 'refreshToken' => 'r-1']))->sole();
    $credential = encryptedUnderLostKey($account->credential);
    usePreviousKeys($withPreviousKeys);

    app(StoreConnection::class)->handle($provider, socialiteUser(['id' => 'u-1', 'token' => 'new-access', 'refreshToken' => $newRefreshToken]));

    // A refresh token nobody can read is none: forgotten with its expiry.
    expect($credential->fresh()->access_token)->toBe('new-access')
        ->and($credential->fresh()->refresh_token)->toBe($newRefreshToken)
        ->and($credential->fresh()->status)->toBe(AccountStatus::Active)
        ->and(SocialToken::count())->toBe(1);
})->with(['google', 'tiktok'])->with(['a new refresh token' => 'r-2', 'no new refresh token' => null])->with('previous keys');

it('lets the member reconnect their LinkedIn organizations over a credential that no longer decrypts', function (bool $withPreviousKeys) {
    Http::fake(['api.linkedin.com/v2/organizationAcls*' => Http::response(['elements' => [[
        'role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'organization~' => ['id' => '111', 'localizedName' => 'Acme'],
    ]]])]);
    $store = app(StoreLinkedInOrganizations::class);
    $store->handle(accessToken: 'member-token', memberId: 'm-1', refreshToken: 'r-1', expiresAt: now()->addDays(60));
    $credential = encryptedUnderLostKey(SocialToken::sole());
    usePreviousKeys($withPreviousKeys);

    $store->handle(accessToken: 'new-member-token', memberId: 'm-1', expiresAt: now()->addDays(60));

    expect($credential->fresh()->access_token)->toBe('new-member-token')
        ->and($credential->fresh()->refresh_token)->toBeNull()
        ->and($credential->fresh()->renew_at)->not->toBeNull();
})->with('previous keys');

it('lets the user reconnect Instagram over a Meta user credential that no longer decrypts', function (bool $extend, bool $withPreviousKeys) {
    fakeUndecryptableMeta();
    app(StoreInstagramAccounts::class)->handle(userToken: 'user-token', userId: 'fb-1');
    SocialToken::query()->get()->each(fn (SocialToken $token) => encryptedUnderLostKey($token));
    usePreviousKeys($withPreviousKeys);

    app(StoreInstagramAccounts::class)->handle(userToken: 'new-user-token', userId: 'fb-1', extend: $extend);

    expect(SocialToken::query()->where('provider_holder_id', 'fb-1')->sole()->access_token)->toBe('new-user-token')
        ->and(SocialToken::query()->where('provider_holder_id', 'page-1')->sole()->access_token)->toBe('new-page-token');
})->with(['a new login' => true, 'a re-sync' => false])->with('previous keys');

// Renewing and posting ----------------------------------------------------------------

/** A Google credential due for renewal, behind one account, whose tokens no longer decrypt. */
function undecryptableDueCredential(): SocialAccount
{
    $account = SocialAccount::factory()->forProvider('google')
        ->credential(fn ($credential) => $credential->dueForRenewal())->create();
    encryptedUnderLostKey($account->credential);

    return $account->fresh();
}

/** Critical log lines written from now on. */
function undecryptableCriticalLogs(): ArrayObject
{
    $logs = new ArrayObject;

    Event::listen(MessageLogged::class, function (MessageLogged $log) use ($logs) {
        if ($log->level === 'critical') {
            $logs->append($log->message);
        }
    });

    return $logs;
}

// A key problem is the operator's, not the user's ------------------------------
//
// A token that does not decrypt usually means a process runs with the wrong
// APP_KEY (a rotation still rolling out, a misconfigured worker): asking every
// user it touches to reconnect would be wrong, and could not be taken back.
// It is retried and alerted, never flagged on its own; the operator flags
// the truly lost ones with social-tokens:reencrypt --flag-undecryptable.

it('keeps a credential that no longer decrypts active when its renewal comes due, and alerts the operator', function () {
    Http::preventStrayRequests();
    Exceptions::fake();
    Event::fake([CredentialNeedsReconnect::class]);
    $alerts = undecryptableCriticalLogs();
    $credential = undecryptableDueCredential()->credential;

    expect(fn () => RenewCredential::dispatchSync($credential))->toThrow(TransientRenewalException::class);

    expect($credential->fresh()->status)->toBe(AccountStatus::Active)
        ->and($alerts->count())->toBeGreaterThan(0)
        ->and(implode("\n", $alerts->getArrayCopy()))->toContain('APP_KEY');

    Event::assertNotDispatched(CredentialNeedsReconnect::class);
    Exceptions::assertNothingReported();
});

it('returns a transient renewal for a credential that no longer decrypts, without calling the provider', function () {
    Http::preventStrayRequests();
    $credential = undecryptableDueCredential()->credential;

    $result = app(SocialTokens::class)->renewCredential($credential);

    expect($result->outcome)->toBe(RenewalOutcome::Transient)
        ->and($result->reason)->toContain('APP_KEY');
});

it('tells the publisher to retry, not to reconnect, when a credential no longer decrypts', function (bool $expired) {
    Http::preventStrayRequests();
    Event::fake([CredentialNeedsReconnect::class]);
    $account = undecryptableDueCredential();

    if ($expired) {
        $account->credential->forceFill(['expires_at' => now()->subMinute()])->save();
    } else {
        $account->credential->forceFill(['expires_at' => now()->addDays(10), 'renew_at' => now()->addDays(9)])->save();
    }

    try {
        app(SocialTokens::class)->validAccessTokenFor($account);
        $this->fail('No NeedsReconnectException.');
    } catch (NeedsReconnectException $e) {
        expect($e->transient)->toBeTrue()
            ->and($e->getMessage())->toContain('APP_KEY');
    }

    expect($account->credential->fresh()->status)->toBe(AccountStatus::Active);
    Event::assertNotDispatched(CredentialNeedsReconnect::class);
})->with(['token still valid' => false, 'token expired' => true]);

it('does not flag a credential that no longer decrypts when a rejection is reported for it', function (bool $terminal) {
    Http::preventStrayRequests();
    $account = undecryptableDueCredential();

    expect(app(SocialTokens::class)->reportRejected($account, 'Rejected at publish.', terminal: $terminal, rejectedToken: 'old-access'))->toBeFalse()
        ->and($account->credential->fresh()->status)->toBe(AccountStatus::Active);
})->with(['terminal' => true, 'to check' => false]);

it('leaves every credential alone when a worker runs with the wrong APP_KEY', function () {
    Http::preventStrayRequests();
    Event::fake([CredentialNeedsReconnect::class]);
    $right = config('app.key');
    $credential = SocialAccount::factory()->forProvider('google')->credential(fn ($credential) => $credential->dueForRenewal())->create()->credential;

    // A worker started with another APP_KEY.
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    usePreviousKeys(false);
    rescue(fn () => RenewCredential::dispatchSync($credential), report: false);

    config(['app.key' => $right]);
    usePreviousKeys(false);

    expect($credential->fresh()->status)->toBe(AccountStatus::Active);
    Event::assertNotDispatched(CredentialNeedsReconnect::class);
});

it('flags the credentials no key decrypts only when the operator asks reencrypt to', function () {
    Event::fake([CredentialNeedsReconnect::class]);
    $lost = undecryptableDueCredential()->credential;
    $readable = SocialAccount::factory()->forProvider('tiktok')->create()->credential;

    $this->artisan('social-tokens:reencrypt')->assertFailed();

    expect($lost->fresh()->status)->toBe(AccountStatus::Active);
    Event::assertNotDispatched(CredentialNeedsReconnect::class);

    $this->artisan('social-tokens:reencrypt', ['--flag-undecryptable' => true])
        ->expectsOutputToContain('Flagged 1 credential(s) needs_reconnect')
        ->assertSuccessful();

    expect($lost->fresh()->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($lost->fresh()->last_error)->toBe(SocialToken::UNDECRYPTABLE)
        ->and($readable->fresh()->status)->toBe(AccountStatus::Active);
    Event::assertDispatchedTimes(CredentialNeedsReconnect::class, 1);
});

it('does not flag a credential a reconnect made readable while the renewal ran', function () {
    $credential = undecryptableDueCredential()->credential;
    $stale = SocialToken::query()->find($credential->getKey());

    // The user reconnects: the row now holds a readable token.
    app(StoreConnection::class)->handle('google', socialiteUser(['id' => $credential->provider_holder_id, 'token' => 'new-access', 'refreshToken' => 'r-2']));

    expect($stale->markNeedsReconnectIfStillHeld('Its tokens no longer decrypt.'))->toBeFalse()
        ->and($credential->fresh()->status)->toBe(AccountStatus::Active);
});
