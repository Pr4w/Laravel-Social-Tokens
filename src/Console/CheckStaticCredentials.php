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
use Pr4w\SocialTokens\Support\Settings;
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
 *  - when a large share of one provider's checked tokens fail at once (an
 *    incident on its side, or the app's own setup), none of that provider's
 *    credentials is flagged and a critical log is raised instead; the same
 *    test over the whole run stops every verdict;
 *  - a credential no active account uses (every account flagged or revoked,
 *    such as a Page the user no longer manages) is not checked: its verdict
 *    would change nothing, and its rejections would count towards the breaker;
 *  - a credential that throws (e.g. cannot be decrypted after an APP_KEY
 *    rotation) is logged and skipped, never flagged;
 *  - a credential whose token changed during the check (reconnected meanwhile)
 *    is left alone.
 *
 * With `check_renewable` on, it also asks about renewable credentials between
 * two renewals (the Meta user token behind Instagram, Threads, LinkedIn), so a
 * token revoked by the user is caught before its renewal comes due. A
 * credential whose renewal is due, or whose token expired, is left to the
 * renewal job. Checks only ask: nothing is renewed or re-dated.
 */
class CheckStaticCredentials extends Command
{
    /** Consecutive non-definitive terminal checks before a credential is flagged. */
    public const STRIKES_TO_FLAG = 2;

    protected $signature = 'social-tokens:check-static';

    protected $description = 'Flag static credentials (e.g. Facebook page tokens), and optionally renewable ones, the provider no longer accepts.';

    public function handle(ConnectorRegistry $registry): int
    {
        $checked = 0;
        $errors = 0;
        $checkedByKind = ['static' => 0, 'renewable' => 0];

        /** @var array<string, int> $checkedByProvider */
        $checkedByProvider = [];

        /** @var array<string, array{credentials: int, reason: ?string, context: array<string, mixed>}> $clientRejected */
        $clientRejected = [];

        /** @var array<int, array{id: int|string, cipher: mixed, reason: string, definitive: bool, strikes: int, kind: string, provider: string}> $terminal */
        $terminal = [];

        $checkRenewable = Settings::checkRenewable();

        // Phase 1: ask the provider about every credential, writing no verdict.
        SocialToken::query()
            ->where('status', AccountStatus::Active->value)
            ->where(function ($query) use ($checkRenewable) {
                $query->where(fn ($query) => $query->whereNull('expires_at')->whereNull('renew_at'));

                if ($checkRenewable) {
                    // Renewable, and not the renewal job's yet.
                    $query->orWhere(fn ($query) => $query->whereNotNull('renew_at')->where('renew_at', '>', now())
                        ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()->addMinute())));
                }
            })
            ->whereHas('accounts', fn ($query) => $query->where('status', AccountStatus::Active->value))
            ->lazyById()
            ->each(function (SocialToken $token) use ($registry, &$checked, &$errors, &$terminal, &$checkedByKind, &$checkedByProvider, &$clientRejected) {
                $kind = $token->renew_at === null ? 'static' : 'renewable';

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
                $checkedByKind[$kind]++;
                $checkedByProvider[$token->provider] = ($checkedByProvider[$token->provider] ?? 0) + 1;
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
                        'kind' => $kind,
                        'provider' => $token->provider,
                    ];
                } elseif ($result->clientError) {
                    $clientRejected[$token->provider] ??= ['credentials' => 0, 'reason' => $result->reason, 'context' => $result->context];
                    $clientRejected[$token->provider]['credentials']++;
                } elseif ($result->unknown && Settings::logUnknownErrors()) {
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

        // The provider refused the app's own credentials (e.g. an
        // appsecret_proof made with a rotated secret): no verdict on the
        // member, one alert per provider.
        foreach ($clientRejected as $provider => $rejection) {
            Log::critical("[social-tokens] {$provider} rejected the app's OAuth client during check-static: check its client_id/client_secret. Credentials are kept.", [
                'provider' => (string) $provider,
                'credentials' => $rejection['credentials'],
                'reason' => $rejection['reason'],
                'context' => $rejection['context'],
            ]);
        }

        // Phase 2: too many rejections at once is the provider's incident, not
        // every user's: flag nothing and alert the operator. Judged over the
        // whole run, then per provider, so a provider-wide rejection is caught
        // even when the other providers' healthy credentials dilute it.
        ['min_checked' => $minChecked, 'max_terminal_ratio' => $maxRatio] = Settings::staticCheckBreaker();

        if ($this->tripsBreaker($checked, count($terminal), $minChecked, $maxRatio)) {
            Log::critical('[social-tokens] check-static aborted: too many credentials rejected at once', [
                'checked' => $checked,
                'terminal' => count($terminal),
                'max_terminal_ratio' => $maxRatio,
            ]);

            $this->info("Checked {$checkedByKind['static']} static credential(s), flagged 0.");
            $this->error('Aborted: '.count($terminal)." of {$checked} credentials were rejected at once; nothing was flagged.");

            return self::FAILURE;
        }

        $terminalByProvider = array_count_values(array_column($terminal, 'provider'));
        $tripped = [];

        foreach ($terminalByProvider as $provider => $rejected) {
            $provider = (string) $provider;

            if (! $this->tripsBreaker($checkedByProvider[$provider] ?? 0, $rejected, $minChecked, $maxRatio)) {
                continue;
            }

            $tripped[$provider] = true;

            Log::critical("[social-tokens] check-static skipped {$provider}: too many of its credentials rejected at once", [
                'provider' => $provider,
                'checked' => $checkedByProvider[$provider],
                'terminal' => $rejected,
                'max_terminal_ratio' => $maxRatio,
            ]);

            $this->error("Aborted for {$provider}: {$rejected} of {$checkedByProvider[$provider]} {$provider} credentials were rejected at once; none of them was flagged.");
        }

        $terminal = array_filter($terminal, fn (array $verdict) => ! isset($tripped[$verdict['provider']]));

        $flagged = ['static' => 0, 'renewable' => 0];

        foreach ($terminal as $verdict) {
            try {
                if ($verdict['definitive'] || $verdict['strikes'] >= self::STRIKES_TO_FLAG) {
                    $flagged[$verdict['kind']] += $this->flag($verdict['id'], $verdict['cipher'], $verdict['reason']) ? 1 : 0;
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

        $this->info("Checked {$checkedByKind['static']} static credential(s), flagged {$flagged['static']}.");

        if ($checkRenewable) {
            $this->info("Checked {$checkedByKind['renewable']} renewable credential(s), flagged {$flagged['renewable']}.");
        }

        if ($errors > 0) {
            $this->warn("{$errors} credential(s) could not be checked; see the log.");
        }

        return $tripped === [] ? self::SUCCESS : self::FAILURE;
    }

    /** More than $maxRatio of at least $minChecked checked credentials came back terminal. */
    protected function tripsBreaker(int $checked, int $terminal, int $minChecked, float $maxRatio): bool
    {
        return $checked > 0 && $checked >= $minChecked && $terminal / $checked > $maxRatio;
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
