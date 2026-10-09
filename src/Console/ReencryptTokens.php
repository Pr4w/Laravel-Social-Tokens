<?php

namespace Pr4w\SocialTokens\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Log;
use Pr4w\SocialTokens\Models\SocialToken;

/**
 * Re-encrypts every stored token with the current APP_KEY. Laravel rotates
 * keys through APP_PREVIOUS_KEYS, but the `encrypted` cast only re-encrypts a
 * value it writes, and some tokens are never written again (a Google refresh
 * token, a Facebook Page token): run this after a rotation, then drop the
 * previous key.
 *
 * A token already under the current key is left as is, so the command can be
 * run again. Each row is written only while it still holds the ciphertext
 * that was read (a renewal or a reconnect meanwhile wrote tokens of its own,
 * under the current key), and without touching updated_at. A token no key
 * decrypts is logged and left: put its key back in APP_PREVIOUS_KEYS, or let
 * the user reconnect.
 */
class ReencryptTokens extends Command
{
    private const COLUMNS = ['access_token', 'refresh_token'];

    protected $signature = 'social-tokens:reencrypt';

    protected $description = 'Re-encrypt every stored token with the current APP_KEY, so the previous keys can be retired.';

    public function handle(): int
    {
        $rewritten = 0;
        $current = 0;
        $failed = 0;

        SocialToken::query()
            ->where(fn ($query) => $query->whereNotNull('access_token')->orWhereNotNull('refresh_token'))
            ->lazyById(200)
            ->each(function (SocialToken $token) use (&$rewritten, &$current, &$failed) {
                try {
                    $changes = $this->reencrypted($token);
                } catch (DecryptException $e) {
                    $failed++;

                    Log::error('[social-tokens] A token could not be decrypted with APP_KEY or APP_PREVIOUS_KEYS; left as it is', [
                        'provider' => $token->provider,
                        'token_id' => $token->getKey(),
                        'exception' => $e::class,
                    ]);

                    return;
                }

                $written = $changes !== [] && SocialToken::query()
                    ->whereKey($token->getKey())
                    ->where('access_token', $token->getRawOriginal('access_token'))
                    ->where('refresh_token', $token->getRawOriginal('refresh_token'))
                    ->toBase()
                    ->update($changes) > 0;

                $written ? $rewritten++ : $current++;
            });

        $this->info("Re-encrypted {$rewritten} credential(s); {$current} already used the current key.");

        if ($failed > 0) {
            $this->error("{$failed} credential(s) could not be decrypted with APP_KEY or APP_PREVIOUS_KEYS; see the log. Keep the previous key until they are reconnected.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * The new ciphertext of each token column the current key does not
     * decrypt, made as the model's `encrypted` cast makes it.
     *
     * @return array<string, string>
     *
     * @throws DecryptException when no key decrypts one
     */
    protected function reencrypted(SocialToken $token): array
    {
        $encrypter = SocialToken::currentEncrypter();
        $changes = [];

        foreach (self::COLUMNS as $column) {
            $cipher = $token->getRawOriginal($column);

            if (! is_string($cipher) || $this->underCurrentKey($cipher)) {
                continue;
            }

            $changes[$column] = $encrypter->encrypt($encrypter->decrypt($cipher, false), false);
        }

        return $changes;
    }

    /**
     * Whether the current key alone decrypts the value. Unknown for an
     * encrypter that is not Laravel's (Model::encryptUsing()): then every
     * value is rewritten.
     */
    protected function underCurrentKey(string $cipher): bool
    {
        $encrypter = SocialToken::currentEncrypter();

        if (! $encrypter instanceof Encrypter) {
            return false;
        }

        try {
            (clone $encrypter)->previousKeys([])->decrypt($cipher, false);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
}
