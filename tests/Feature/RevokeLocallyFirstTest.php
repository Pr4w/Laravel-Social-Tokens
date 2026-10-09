<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Pr4w\SocialTokens\Connectors\GoogleConnector;
use Pr4w\SocialTokens\Connectors\TikTokConnector;
use Pr4w\SocialTokens\Connectors\XConnector;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\CredentialRevoked;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Pr4w\SocialTokens\Tests\Fixtures\FakeConnector;

/*
 * The user asked to disconnect: that decision is recorded whatever the
 * provider-side revocation does. revoke() used to call the provider first,
 * unguarded, and the built-in connectors decrypted the token outside their
 * try blocks, so a credential that cannot be decrypted (APP_KEY changed
 * without APP_PREVIOUS_KEYS) or a custom connector that throws left the
 * credential, and with revoke() every account, active.
 */

beforeEach(fn () => FakeConnector::reset());

/** A credential (and one account) whose token columns were encrypted under another key. */
function revokeFirstUndecryptable(string $provider): SocialToken
{
    $token = SocialToken::create([
        'provider' => $provider,
        'provider_holder_id' => "{$provider}-holder",
        'access_token' => 'access',
        'refresh_token' => 'refresh',
        'status' => AccountStatus::Active,
    ]);

    SocialAccount::create([
        'provider' => $provider,
        'provider_user_id' => "{$provider}-holder",
        'social_token_id' => $token->getKey(),
        'status' => AccountStatus::Active,
    ]);

    $other = new Encrypter(random_bytes(32), 'AES-256-CBC');

    DB::table('social_tokens')->where('id', $token->getKey())->update([
        'access_token' => $other->encryptString('access'),
        'refresh_token' => $other->encryptString('refresh'),
    ]);

    return $token->fresh();
}

function revokeFirstFake(): SocialToken
{
    $token = SocialToken::create(['provider' => 'fake', 'provider_holder_id' => 'h', 'access_token' => 'access', 'status' => AccountStatus::Active]);
    SocialAccount::create(['provider' => 'fake', 'provider_user_id' => 'a', 'social_token_id' => $token->getKey(), 'status' => AccountStatus::Active]);

    return $token;
}

it('revokes a credential it cannot decrypt, with every account', function (string $provider) {
    Http::fake();
    Log::spy();
    $token = revokeFirstUndecryptable($provider);

    app(SocialTokens::class)->revoke($token);

    expect($token->fresh()->status)->toBe(AccountStatus::Revoked)
        ->and($token->accounts()->first()->status)->toBe(AccountStatus::Revoked);

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'revocation failed'))->once();
})->with(['tiktok', 'google', 'x']);

it('disconnects the last account of a credential it cannot decrypt', function (string $provider) {
    Http::fake();
    $token = revokeFirstUndecryptable($provider);

    app(SocialTokens::class)->disconnect($token->accounts()->first());

    expect($token->fresh()->status)->toBe(AccountStatus::Revoked);
})->with(['tiktok', 'google', 'x']);

it('never throws from a built-in revoke, even for a token it cannot decrypt, and logs it', function (string $provider, string $connector) {
    Http::fake();
    Log::spy();
    $token = revokeFirstUndecryptable($provider);

    $revoke = app(ConnectorRegistry::class)->for($provider);
    $revoke->revoke($token);

    expect($revoke)->toBeInstanceOf($connector);

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === "[social-tokens] {$provider} token revocation failed"
            && $context['exception'] === DecryptException::class)
        ->once();
})->with([
    ['tiktok', TikTokConnector::class],
    ['google', GoogleConnector::class],
    ['x', XConnector::class],
]);

it('revokes locally even when a custom connector throws, and logs it without secrets', function () {
    FakeConnector::$revokeUsing = fn () => throw new RuntimeException('cURL error 7: refused for https://provider.test/revoke?token=secret-in-url');
    Log::spy();
    $token = revokeFirstFake();

    app(SocialTokens::class)->revoke($token);

    expect($token->fresh()->status)->toBe(AccountStatus::Revoked)
        ->and($token->accounts()->first()->status)->toBe(AccountStatus::Revoked)
        ->and(FakeConnector::$revokeCalls)->toBe(1);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => str_contains($message, 'revocation failed')
            && $context['token_id'] === $token->getKey()
            && $context['exception'] === RuntimeException::class
            && ! str_contains(json_encode($context), 'secret-in-url'))
        ->once();
});

it('records the revocation before calling the provider', function () {
    $seen = [];
    FakeConnector::$revokeUsing = function (SocialToken $token) use (&$seen) {
        $seen = [
            'credential' => SocialToken::query()->find($token->getKey())->status,
            'account' => SocialAccount::query()->where('social_token_id', $token->getKey())->value('status'),
            'access_token' => $token->access_token,
        ];
    };

    app(SocialTokens::class)->revoke(revokeFirstFake());

    expect($seen)->toBe([
        'credential' => AccountStatus::Revoked,
        'account' => AccountStatus::Revoked,
        'access_token' => 'access',
    ]);
});

it('hands the provider the token even when a revocation listener wipes it', function () {
    Event::listen(CredentialRevoked::class, fn (CredentialRevoked $event) => $event->token
        ->forceFill(['access_token' => null, 'refresh_token' => null])->save());

    $sent = null;
    FakeConnector::$revokeUsing = function (SocialToken $token) use (&$sent) {
        $sent = $token->access_token;
    };

    app(SocialTokens::class)->revoke(revokeFirstFake());

    expect($sent)->toBe('access');
});
