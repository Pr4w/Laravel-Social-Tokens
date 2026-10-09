<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Pr4w\SocialTokens\Models\SocialToken;

/*
 * Laravel rotates APP_KEY through APP_PREVIOUS_KEYS, but the `encrypted` cast
 * only re-encrypts a value it writes. A Google refresh token or a Facebook
 * Page token is never written again, so the old key could never be retired:
 * once dropped, those credentials no longer decrypted, and publishing threw a
 * DecryptException.
 */

/** Rebuild the encrypter from the config, as a new process would. */
function reencryptUseKeys(string $key, array $previous = []): void
{
    config(['app.key' => 'base64:'.base64_encode($key), 'app.previous_keys' => array_map(fn ($k) => 'base64:'.base64_encode($k), $previous)]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
}

/** A credential whose token columns hold ciphertext made with $key. */
function reencryptCredential(string $key, string $holder, ?string $refresh = 'refresh'): SocialToken
{
    $token = SocialToken::create(['provider' => 'google', 'provider_holder_id' => $holder, 'access_token' => 'x']);
    $encrypter = new Encrypter($key, 'aes-256-cbc');

    DB::table('social_tokens')->where('id', $token->getKey())->update([
        'access_token' => $encrypter->encryptString("{$holder}-access"),
        'refresh_token' => $refresh === null ? null : $encrypter->encryptString("{$holder}-{$refresh}"),
        'updated_at' => now()->subWeek(),
    ]);

    return $token;
}

beforeEach(function () {
    $this->old = random_bytes(32);
    $this->new = random_bytes(32);
    reencryptUseKeys($this->old);
});

it('re-encrypts every token with the current key, so the previous one can go', function () {
    $google = reencryptCredential($this->old, 'g-1');
    $page = reencryptCredential($this->old, 'page-1', refresh: null);
    $updatedAt = DB::table('social_tokens')->where('id', $google->getKey())->value('updated_at');

    reencryptUseKeys($this->new, previous: [$this->old]);

    $this->artisan('social-tokens:reencrypt')
        ->expectsOutputToContain('Re-encrypted 2 credential(s)')
        ->assertSuccessful();

    reencryptUseKeys($this->new); // the old key retired

    expect($google->fresh()->access_token)->toBe('g-1-access')
        ->and($google->fresh()->refresh_token)->toBe('g-1-refresh')
        ->and($page->fresh()->access_token)->toBe('page-1-access')
        ->and($page->fresh()->refresh_token)->toBeNull()
        ->and(DB::table('social_tokens')->where('id', $google->getKey())->value('updated_at'))->toBe($updatedAt);
});

it('leaves the tokens already under the current key as they are', function () {
    $token = reencryptCredential($this->new, 'g-1');
    $before = DB::table('social_tokens')->where('id', $token->getKey())->first(['access_token', 'refresh_token']);

    reencryptUseKeys($this->new, previous: [$this->old]);

    $this->artisan('social-tokens:reencrypt')
        ->expectsOutputToContain('Re-encrypted 0 credential(s); 1 already used the current key.')
        ->assertSuccessful();

    expect(DB::table('social_tokens')->where('id', $token->getKey())->first(['access_token', 'refresh_token']))->toEqual($before);
});

it('reports the tokens no key decrypts, and leaves them', function () {
    $lost = reencryptCredential(random_bytes(32), 'lost');
    $kept = reencryptCredential($this->old, 'kept');
    $cipher = DB::table('social_tokens')->where('id', $lost->getKey())->value('access_token');
    Log::spy();

    reencryptUseKeys($this->new, previous: [$this->old]);

    $this->artisan('social-tokens:reencrypt')
        ->expectsOutputToContain('1 credential(s) could not be decrypted')
        ->assertFailed();

    expect(DB::table('social_tokens')->where('id', $lost->getKey())->value('access_token'))->toBe($cipher)
        ->and($kept->fresh()->access_token)->toBe('kept-access');

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context) => str_contains($message, 'could not be decrypted')
            && $context['token_id'] === $lost->getKey()
            && $context['exception'] === DecryptException::class)
        ->once();
});

it('never writes over a token renewed while it ran', function () {
    $token = reencryptCredential($this->old, 'g-1');

    reencryptUseKeys($this->new, previous: [$this->old]);

    // A renewal lands between the command's read and its write.
    $renewed = false;
    DB::listen(function ($query) use (&$renewed, $token) {
        if (! $renewed && preg_match('/^select .* from [`"]?\w*social_tokens[`"]? /', $query->sql)) {
            $renewed = true;
            SocialToken::query()->findOrFail($token->getKey())->forceFill(['access_token' => 'renewed', 'refresh_token' => 'renewed-refresh'])->save();
        }
    });

    $this->artisan('social-tokens:reencrypt')->assertSuccessful();

    expect($token->fresh()->access_token)->toBe('renewed')
        ->and($token->fresh()->refresh_token)->toBe('renewed-refresh');
});
