<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Pr4w\SocialTokens\Actions\HandleDataDeletionCallback;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\AccountRevoked;
use Pr4w\SocialTokens\Events\CredentialRevoked;
use Pr4w\SocialTokens\Events\ExternalUserDataDeleted;
use Pr4w\SocialTokens\Exceptions\InvalidSignedRequestException;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\SocialTokensServiceProvider;
use Pr4w\SocialTokens\Support\CallbackRoutes;
use Pr4w\SocialTokens\Support\DeletionConfirmation;
use Pr4w\SocialTokens\Support\MetaSignedRequest;
use Pr4w\SocialTokens\Tests\Fixtures\Owner;

/*
 * When a user removes the app, Meta posts a signed_request to the app's
 * Deauthorize callback, and to its Data Deletion Request callback when they
 * ask for their data to be deleted (Threads: Uninstall and Delete). The
 * package owned all of that data but could not act on either: no verifier,
 * no handler, and no way to reach every row of one external user across
 * owners. A user credential is keyed by the Facebook user, but each Page's
 * credential by the Page, found only through the accounts' holder.
 */

beforeEach(fn () => Http::fake());

/**
 * A Facebook user's connection for one owner: their user credential, behind
 * an Instagram account, and one Page with its own token.
 *
 * @return array{user: SocialToken, page: SocialToken, instagram: SocialAccount, facebook: SocialAccount}
 */
function metaConnection(string $userId, ?Owner $owner = null): array
{
    $ownerKey = SocialAccount::ownerKey($owner);

    $user = SocialToken::create(['provider' => 'facebook', 'provider_holder_id' => $userId, 'access_token' => "user-{$userId}", 'renew_at' => now()->addDays(50)] + $ownerKey);
    $page = SocialToken::create(['provider' => 'facebook', 'provider_holder_id' => "page-{$userId}", 'access_token' => "page-{$userId}"] + $ownerKey);

    return [
        'user' => $user,
        'page' => $page,
        'instagram' => SocialAccount::create(['provider' => 'instagram', 'provider_user_id' => "ig-{$userId}", 'provider_holder_id' => $userId, 'social_token_id' => $user->getKey()] + $ownerKey),
        'facebook' => SocialAccount::create(['provider' => 'facebook', 'provider_user_id' => "page-{$userId}", 'provider_holder_id' => $userId, 'social_token_id' => $page->getKey()] + $ownerKey),
    ];
}

function signedFor(string $userId, string $secret = 'facebook-secret'): string
{
    return MetaSignedRequest::make(['user_id' => $userId, 'issued_at' => now()->getTimestamp()], $secret);
}

function enableCallbacks(array $config = []): void
{
    config()->set('social-tokens.callbacks', $config + ['enabled' => true]);
    CallbackRoutes::register();
}

// Signed requests ------------------------------------------------------------

it('reads a signed_request signed with the app secret', function () {
    $payload = MetaSignedRequest::parse(signedFor('user-1'), 'facebook-secret');

    expect($payload['user_id'])->toBe('user-1')
        ->and($payload['algorithm'])->toBe('HMAC-SHA256');
});

it('refuses a signed_request that does not verify', function (Closure $signedRequest, string $secret) {
    MetaSignedRequest::parse($signedRequest(), $secret);
})->with([
    'another secret' => [fn () => signedFor('user-1', 'other-secret'), 'facebook-secret'],
    'a forged payload' => [fn () => explode('.', signedFor('user-1'))[0].'.'.rtrim(strtr(base64_encode('{"algorithm":"HMAC-SHA256","user_id":"user-2"}'), '+/', '-_'), '='), 'facebook-secret'],
    'another algorithm' => [fn () => MetaSignedRequest::make(['user_id' => 'user-1', 'algorithm' => 'HMAC-SHA1'], 'facebook-secret'), 'facebook-secret'],
    'one part' => [fn () => 'abc', 'facebook-secret'],
    'an empty part' => [fn () => '.abc', 'facebook-secret'],
    'not base64url' => [fn () => 'a*b.c$d', 'facebook-secret'],
    'not JSON' => [fn () => 'YWJj.YWJj', 'facebook-secret'],
    'no secret to check it with' => [fn () => signedFor('user-1'), ''],
])->throws(InvalidSignedRequestException::class);

// Revoking and purging an external user ----------------------------------------

it('revokes every credential and account of a Facebook user, under every owner', function () {
    Event::fake([CredentialRevoked::class, AccountRevoked::class]);

    $first = metaConnection('user-1', Owner::create(['name' => 'first']));
    $second = metaConnection('user-1', Owner::create(['name' => 'second']));
    $other = metaConnection('user-2');

    $accounts = app(SocialTokens::class)->revokeExternalUser('facebook', 'user-1');

    expect($accounts)->toHaveCount(4);

    foreach ([$first, $second] as $rows) {
        foreach ($rows as $row) {
            expect($row->fresh()->status)->toBe(AccountStatus::Revoked);
        }
    }

    foreach ($other as $row) {
        expect($row->fresh()->status)->toBe(AccountStatus::Active);
    }

    Event::assertDispatchedTimes(CredentialRevoked::class, 4); // two user credentials, two Pages
    Event::assertDispatchedTimes(AccountRevoked::class, 4);
    Http::assertNothingSent(); // the user removed the app: Meta already knows
});

it('reaches the same rows from the instagram key', function () {
    $rows = metaConnection('user-1');

    app(SocialTokens::class)->revokeExternalUser('instagram', 'user-1');

    foreach ($rows as $row) {
        expect($row->fresh()->status)->toBe(AccountStatus::Revoked);
    }
});

it('revokes a Threads user', function () {
    $token = SocialToken::create(['provider' => 'threads', 'provider_holder_id' => 'th-1', 'access_token' => 'th-token']);
    $account = SocialAccount::create(['provider' => 'threads', 'provider_user_id' => 'th-1', 'provider_holder_id' => 'th-1', 'social_token_id' => $token->getKey()]);

    app(SocialTokens::class)->revokeExternalUser('threads', 'th-1');

    expect($token->fresh()->status)->toBe(AccountStatus::Revoked)
        ->and($account->fresh()->status)->toBe(AccountStatus::Revoked);
});

it('revokes every row even when a listener throws, then rethrows', function () {
    $rows = metaConnection('user-1');
    Event::listen(CredentialRevoked::class, fn () => throw new RuntimeException('mail server down'));

    expect(fn () => app(SocialTokens::class)->revokeExternalUser('facebook', 'user-1'))
        ->toThrow(RuntimeException::class, 'mail server down');

    foreach ($rows as $row) {
        expect($row->fresh()->status)->toBe(AccountStatus::Revoked);
    }
});

it('lets a reconnect bring a revoked user back', function () {
    $rows = metaConnection('user-1');
    app(SocialTokens::class)->revokeExternalUser('facebook', 'user-1');

    SocialToken::persistCredential(['provider' => 'facebook', 'provider_holder_id' => 'user-1'] + SocialAccount::ownerKey(null), ['access_token' => 'new']);

    expect($rows['user']->fresh()->status)->toBe(AccountStatus::Active);
});

it('deletes the tokens of an external user and keeps their accounts, revoked', function () {
    Event::fake([CredentialRevoked::class, AccountRevoked::class]);

    $rows = metaConnection('user-1');
    $other = metaConnection('user-2');

    $accounts = app(SocialTokens::class)->purgeExternalUser('facebook', 'user-1');

    expect($accounts->modelKeys())->toEqualCanonicalizing([$rows['instagram']->getKey(), $rows['facebook']->getKey()])
        ->and(SocialToken::query()->whereKey([$rows['user']->getKey(), $rows['page']->getKey()])->exists())->toBeFalse()
        ->and($rows['instagram']->fresh()->status)->toBe(AccountStatus::Revoked)
        ->and($rows['instagram']->fresh()->social_token_id)->toBeNull()
        ->and($rows['facebook']->fresh()->social_token_id)->toBeNull()
        ->and($accounts->every(fn (SocialAccount $account) => $account->social_token_id === null))->toBeTrue()
        ->and($other['user']->fresh()->status)->toBe(AccountStatus::Active)
        ->and($other['instagram']->fresh()->social_token_id)->toBe($other['user']->getKey());

    Event::assertDispatchedTimes(AccountRevoked::class, 2);
    Event::assertNotDispatched(CredentialRevoked::class);
});

it('deletes the tokens even when an account listener throws', function () {
    $rows = metaConnection('user-1');
    Event::listen(AccountRevoked::class, fn () => throw new RuntimeException('listener failed'));

    expect(fn () => app(SocialTokens::class)->purgeExternalUser('facebook', 'user-1'))->toThrow(RuntimeException::class);

    expect(SocialToken::query()->whereKey($rows['user']->getKey())->exists())->toBeFalse();
});

// The callback routes ----------------------------------------------------------

it('registers no route unless enabled', function () {
    $this->post('/social-tokens/meta/facebook/deauthorize', ['signed_request' => signedFor('user-1')])->assertNotFound();

    expect(app('router')->has('social-tokens.callbacks.deauthorize'))->toBeFalse();

    config()->set('social-tokens.callbacks.enabled', true);
    (fn () => $this->registerCallbackRoutes())->call(app()->getProvider(SocialTokensServiceProvider::class));
    app('router')->getRoutes()->refreshNameLookups();

    expect(app('router')->has('social-tokens.callbacks.deauthorize'))->toBeTrue()
        ->and(route('social-tokens.callbacks.delete', ['provider' => 'facebook'], false))->toBe('/social-tokens/meta/facebook/delete');
});

it('revokes the user named by a verified deauthorize callback', function () {
    enableCallbacks();
    $rows = metaConnection('user-1');

    $this->post('/social-tokens/meta/facebook/deauthorize', ['signed_request' => signedFor('user-1')])
        ->assertOk()
        ->assertJson(['success' => true]);

    expect($rows['instagram']->fresh()->status)->toBe(AccountStatus::Revoked)
        ->and($rows['page']->fresh()->status)->toBe(AccountStatus::Revoked);
});

it('refuses a callback that does not verify, and changes nothing', function () {
    enableCallbacks();
    Log::spy();
    $rows = metaConnection('user-1');

    $this->post('/social-tokens/meta/facebook/deauthorize', ['signed_request' => signedFor('user-1', 'attacker-secret')])->assertStatus(400);
    $this->post('/social-tokens/meta/facebook/delete', ['signed_request' => signedFor('user-1', 'attacker-secret')])->assertStatus(400);
    $this->post('/social-tokens/meta/facebook/deauthorize', [])->assertStatus(400);
    $this->post('/social-tokens/meta/facebook/deauthorize', ['signed_request' => MetaSignedRequest::make(['issued_at' => 1], 'facebook-secret')])->assertStatus(400);

    expect($rows['user']->fresh()->status)->toBe(AccountStatus::Active)
        ->and(SocialToken::count())->toBe(2);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => str_contains($message, 'does not verify')
        && $context['provider'] === 'facebook')->atLeast()->once();
});

it('answers 404 for a provider that cannot verify a callback', function (string $provider) {
    enableCallbacks();

    $this->post("/social-tokens/meta/{$provider}/deauthorize", ['signed_request' => signedFor('user-1')])->assertNotFound();
    $this->post("/social-tokens/meta/{$provider}/delete", ['signed_request' => signedFor('user-1')])->assertNotFound();
})->with(['tiktok', 'unknown']);

it('verifies Threads and Instagram Login callbacks with their own app secret', function (string $provider) {
    enableCallbacks();
    $token = SocialToken::create(['provider' => $provider, 'provider_holder_id' => 'u-1', 'access_token' => 't']);

    $this->post("/social-tokens/meta/{$provider}/deauthorize", ['signed_request' => signedFor('u-1', 'facebook-secret')])->assertStatus(400);
    expect($token->fresh()->status)->toBe(AccountStatus::Active);

    $this->post("/social-tokens/meta/{$provider}/deauthorize", ['signed_request' => signedFor('u-1', "{$provider}-secret")])->assertOk();
    expect($token->fresh()->status)->toBe(AccountStatus::Revoked);
})->with(['threads', 'instagram_login']);

it('deletes the tokens of a data deletion request and answers with a status page', function () {
    enableCallbacks();
    Event::fake([ExternalUserDataDeleted::class]);
    $rows = metaConnection('user-1');

    $response = $this->post('/social-tokens/meta/facebook/delete', ['signed_request' => signedFor('user-1')])
        ->assertOk()
        ->assertJsonStructure(['url', 'confirmation_code']);

    $code = $response->json('confirmation_code');

    expect(SocialToken::count())->toBe(0)
        ->and($rows['instagram']->fresh()->status)->toBe(AccountStatus::Revoked)
        ->and($response->json('url'))->toBe(url("/social-tokens/meta/facebook/deletion/{$code}"));

    Event::assertDispatched(ExternalUserDataDeleted::class, fn ($event) => $event->provider === 'facebook'
        && $event->externalId === 'user-1'
        && $event->confirmationCode === $code
        && collect($event->accountIds)->sort()->values()->all() === collect([$rows['instagram']->getKey(), $rows['facebook']->getKey()])->sort()->values()->all());

    $this->get("/social-tokens/meta/facebook/deletion/{$code}")->assertOk()->assertSee('completed');
    $forged = substr($code, 0, -1).($code[-1] === '0' ? '1' : '0');

    $this->get("/social-tokens/meta/facebook/deletion/{$forged}")->assertNotFound();
    $this->get("/social-tokens/meta/threads/deletion/{$code}")->assertNotFound(); // signed for facebook only
});

it('points the answer at the app\'s own status page', function (string $configured, string $expected) {
    enableCallbacks(['deletion_status_url' => $configured]);

    $answer = app(HandleDataDeletionCallback::class)->handle('facebook', signedFor('user-1'));

    expect($answer['url'])->toBe(str_replace('CODE', $answer['confirmation_code'], $expected));
})->with([
    'placeholders' => ['https://app.test/privacy/deletion/{provider}/{code}', 'https://app.test/privacy/deletion/facebook/CODE'],
    'no placeholder' => ['https://app.test/privacy/deletion', 'https://app.test/privacy/deletion?code=CODE'],
    'a query already' => ['https://app.test/privacy?tab=deletion', 'https://app.test/privacy?tab=deletion&code=CODE'],
]);

it('deletes nothing when it has no status page to point to', function () {
    $rows = metaConnection('user-1');

    expect(fn () => app(HandleDataDeletionCallback::class)->handle('facebook', signedFor('user-1')))
        ->toThrow(RuntimeException::class, 'No data deletion status page');

    expect($rows['user']->fresh())->not->toBeNull();
});

it('issues confirmation codes it can recognise, and nobody else can forge', function () {
    $this->freezeSecond();

    $code = DeletionConfirmation::issue('facebook');

    expect(DeletionConfirmation::issuedAt('facebook', $code)?->equalTo(now()))->toBeTrue()
        ->and(DeletionConfirmation::issuedAt('threads', $code))->toBeNull()
        ->and(DeletionConfirmation::issuedAt('facebook', 'abc'))->toBeNull()
        ->and(DeletionConfirmation::issue('facebook'))->not->toBe($code);

    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    expect(DeletionConfirmation::issuedAt('facebook', $code))->toBeNull();
});
