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
 * that was read, and without touching updated_at: a renewal or a reconnect
 * that wrote meanwhile is kept, and the row is read again and rewritten,
 * since such a write may have left a token under the old key (a Google
 * renewal rewrites only the access token). A row still changing after a few
 * attempts fails the command, as does a token no key decrypts, which is
 * logged and left: put its key back in APP_PREVIOUS_KEYS, or let the user
 * reconnect (its next renewal or publish flags it needs_reconnect, and a
 * reconnect writes a new token over it).
 */
class ReencryptTokens extends Command
{
    private const COLUMNS = ['access_token', 'refresh_token'];

    /** Writes tried on a row before it counts as changing under the command. */
    private const ATTEMPTS = 3;

    protected $signature = 'social-tokens:reencrypt';

    protected $description = 'Re-encrypt every stored token with the current APP_KEY, so the previous keys can be retired.';

    public function handle(): int
    {
        $counts = ['rewritten' => 0, 'current' => 0, 'changed' => 0, 'failed' => 0, 'gone' => 0];

        SocialToken::query()
            ->where(fn ($query) => $query->whereNotNull('access_token')->orWhereNotNull('refresh_token'))
            ->lazyById(200)
            ->each(function (SocialToken $token) use (&$counts) {
                $counts[$this->rewrite($token)]++;
            });

        $this->info("Re-encrypted {$counts['rewritten']} credential(s); {$counts['current']} already used the current key.");

        if ($counts['changed'] > 0) {
            $this->error("{$counts['changed']} credential(s) changed while being re-encrypted; run the command again.");
        }

        if ($counts['failed'] > 0) {
            $this->error("{$counts['failed']} credential(s) could not be decrypted with APP_KEY or APP_PREVIOUS_KEYS; see the log. Keep the previous key until they are reconnected.");
        }

        return $counts['changed'] > 0 || $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Rewrite one row's tokens under the current key, reading it again
     * whenever it changed between the read and the write.
     *
     * @return 'rewritten'|'current'|'changed'|'failed'|'gone'
     */
    protected function rewrite(SocialToken $token): string
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $changes = $this->reencrypted($token);
            } catch (DecryptException $e) {
                Log::error('[social-tokens] A token could not be decrypted with APP_KEY or APP_PREVIOUS_KEYS; left as it is', [
                    'provider' => $token->provider,
                    'token_id' => $token->getKey(),
                    'exception' => $e::class,
                ]);

                return 'failed';
            }

            if ($changes === []) {
                return 'current';
            }

            $written = SocialToken::query()
                ->whereKey($token->getKey())
                ->where('access_token', $token->getRawOriginal('access_token'))
                ->where('refresh_token', $token->getRawOriginal('refresh_token'))
                ->toBase()
                ->update($changes) > 0;

            if ($written) {
                return 'rewritten';
            }

            if ($attempt === self::ATTEMPTS) {
                return 'changed';
            }

            $token = SocialToken::query()->useWritePdo()->whereKey($token->getKey())->first();

            // Deleted meanwhile: nothing left under the old key.
            if ($token === null) {
                return 'gone';
            }
        }
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
