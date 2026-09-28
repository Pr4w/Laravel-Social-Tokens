<?php

namespace Pr4w\SocialTokens\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Pr4w\SocialTokens\Contracts\ChecksCredential;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Events\CredentialNeedsReconnect;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Throwable;

/**
 * Static credentials (e.g. Facebook page tokens) never expire and are never
 * renewed, so a token the provider invalidated (password change, app removed,
 * admin role lost) would otherwise only be discovered by a failed post. This
 * asks the provider about each one and flags the dead ones.
 *
 * A verdict cuts the account off, so the run is deliberately cautious:
 *  - a definitive answer (a Meta session subcode) flags at once; any other
 *    terminal answer must be confirmed by the next run (two strikes);
 *  - when a large share of the checked tokens fail at once (a provider-side
 *    incident), nothing is flagged and a critical log is raised instead;
 *  - a credential that throws (e.g. cannot be decrypted after an APP_KEY
 *    rotation) is logged and skipped, never flagged;
 *  - a credential whose token changed during the check (reconnected meanwhile)
 *    is left alone.
 */
class CheckStaticCredentials extends Command
{
    /** Consecutive non-definitive terminal checks before a credential is flagged. */
    public const STRIKES_TO_FLAG = 2;

    protected $signature = 'social-tokens:check-static';

    protected $description = 'Flag static credentials (e.g. Facebook page tokens) the provider no longer accepts.';

    public function handle(ConnectorRegistry $registry): int
    {
        $checked = 0;
        $errors = 0;

        /** @var array<int, array{id: int|string, cipher: mixed, reason: string, definitive: bool, strikes: int}> $terminal */
        $terminal = [];

        // Phase 1: ask the provider about every credential, writing no verdict.
        SocialToken::query()
            ->where('status', AccountStatus::Active->value)
            ->whereNull('expires_at')
            ->whereNull('renew_at')
            ->has('accounts')
            ->lazyById()
            ->each(function (SocialToken $token) use ($registry, &$checked, &$errors, &$terminal) {
                if (! $registry->has($token->provider)) {
                    return;
                }

                $connector = $registry->for($token->provider);

                if (! $connector instanceof ChecksCredential) {
                    return;
                }

                try {
                    $result = $connector->checkCredential($token);
                } catch (Throwable $e) {
                    $errors++;

                    Log::error('[social-tokens] Static credential check failed', [
                        'provider' => $token->provider,
                        'token_id' => $token->getKey(),
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]);

                    return;
                }

                $checked++;
                $cipher = $token->getRawOriginal('access_token');

                if ($result->succeeded()) {
                    if ($token->failed_checks > 0) {
                        SocialToken::query()->whereKey($token->getKey())->where('access_token', $cipher)->update(['failed_checks' => 0]);
                    }
                } elseif ($result->outcome === RenewalOutcome::Terminal) {
                    $terminal[] = [
                        'id' => $token->getKey(),
                        'cipher' => $cipher,
                        'reason' => (string) $result->reason,
                        'definitive' => (bool) ($result->context['definitive'] ?? false),
                        'strikes' => $token->failed_checks + 1,
                    ];
                } elseif ($result->unknown && config('social-tokens.log_unknown_errors', true)) {
                    Log::error('[social-tokens] Uncatalogued credential check error', [
                        'provider' => $token->provider,
                        'token_id' => $token->getKey(),
                        'reason' => $result->reason,
                        'context' => $result->context,
                    ]);
                }
            });

        if ($checked === 0 && $errors > 0) {
            Log::critical('[social-tokens] check-static could not check any credential', ['errors' => $errors]);
        }

        // Phase 2: too many rejections at once is the provider's incident, not
        // every user's: flag nothing and alert the operator.
        $breaker = (array) config('social-tokens.check_static_breaker', []);
        $minChecked = (int) ($breaker['min_checked'] ?? 10);
        $maxRatio = (float) ($breaker['max_terminal_ratio'] ?? 0.2);

        if ($checked >= $minChecked && count($terminal) / $checked > $maxRatio) {
            Log::critical('[social-tokens] check-static aborted: too many credentials rejected at once', [
                'checked' => $checked,
                'terminal' => count($terminal),
                'max_terminal_ratio' => $maxRatio,
            ]);

            $this->info("Checked {$checked} static credential(s), flagged 0.");
            $this->error('Aborted: '.count($terminal)." of {$checked} credentials were rejected at once; nothing was flagged.");

            return self::FAILURE;
        }

        $flagged = 0;

        foreach ($terminal as $verdict) {
            try {
                if ($verdict['definitive'] || $verdict['strikes'] >= self::STRIKES_TO_FLAG) {
                    $flagged += $this->flag($verdict['id'], $verdict['cipher'], $verdict['reason']) ? 1 : 0;
                } else {
                    SocialToken::query()
                        ->whereKey($verdict['id'])
                        ->where('status', AccountStatus::Active->value)
                        ->where('access_token', $verdict['cipher'])
                        ->update(['failed_checks' => $verdict['strikes']]);
                }
            } catch (Throwable $e) {
                Log::error('[social-tokens] Could not record a static credential check', [
                    'token_id' => $verdict['id'],
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Checked {$checked} static credential(s), flagged {$flagged}.");

        if ($errors > 0) {
            $this->warn("{$errors} credential(s) could not be checked; see the log.");
        }

        return self::SUCCESS;
    }

    /**
     * Flag only if the row still holds the token that was checked: a reconnect
     * during the check wrote a new one, which this verdict says nothing about.
     */
    protected function flag(int|string $id, mixed $cipher, string $reason): bool
    {
        $changed = SocialToken::query()
            ->whereKey($id)
            ->where('status', AccountStatus::Active->value)
            ->where('access_token', $cipher)
            ->update([
                'status' => AccountStatus::NeedsReconnect->value,
                'last_error' => $reason,
                'failed_checks' => 0,
            ]);

        if ($changed === 0) {
            return false;
        }

        $token = SocialToken::query()->find($id);

        if ($token !== null) {
            event(new CredentialNeedsReconnect($token, $reason));
        }

        return true;
    }
}
