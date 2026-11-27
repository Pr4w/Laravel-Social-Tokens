<?php

namespace Pr4w\SocialTokens\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Pr4w\SocialTokens\Contracts\ChecksCredential;
use Pr4w\SocialTokens\Contracts\ListsReachableAccounts;
use Pr4w\SocialTokens\Contracts\VerifiesMissingAccounts;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Events\CredentialNeedsReconnect;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Pr4w\SocialTokens\Support\RenewalResult;
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
 * With `check_renewable` on (the default), it also asks about renewable
 * credentials between two renewals (the Meta user token behind Instagram,
 * Instagram Login, Threads, LinkedIn, Pinterest), so a token revoked by the
 * user is caught within a day rather than at its renewal, weeks later. A
 * credential whose renewal is due, or whose token expired, is left to the
 * renewal job. Checks only ask: nothing is renewed or re-dated.
 *
 * With `reconcile_accounts` on (the default), it then lists, for each token
 * several accounts post with, the accounts its holder can still post as
 * (Contracts\ListsReachableAccounts: a LinkedIn member's organizations, the
 * Instagram accounts of a Facebook user's Pages), and flags those missing
 * from it, as a reconnect would, once a second read confirms it. The token
 * keeps working there, so nothing else notices. Same safeguards: a list read
 * whole or nothing decided, and the breaker, per account provider.
 */
class CheckStaticCredentials extends Command
{
    /** How long an account missing from a list that is the only sign must stay missing before it is flagged. */
    protected const MISSING_FOR_SECONDS = 20 * 3600;

    /** How long a note of missing accounts waits for the run that confirms it. */
    protected const CONFIRM_WITHIN_SECONDS = 48 * 3600;

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
            ->whereRelation('accounts', 'status', AccountStatus::Active->value)
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
                    // A check asking for a refresh to decide is settled now.
                    $result = app(SocialTokens::class)->settleCheckByRefresh($token, $connector->checkCredential($token));
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
                    // A fresh token for the same grant (a Facebook Page token
                    // minted again): stored in place of the stale one.
                    if ($result->accessToken !== null && $result->accessToken !== $token->access_token) {
                        if ($token->replaceCheckedAccessToken($cipher, $result->accessToken)) {
                            Log::info('[social-tokens] Replaced a token the provider refused with a fresh one for the same grant', [
                                'provider' => $token->provider,
                                'token_id' => $token->getKey(),
                            ]);
                        }
                    } elseif ($token->failed_checks > 0) {
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

        // A provider phase 1 found in an incident is not reconciled either.
        $reconcileTripped = $this->reconcileAccounts($registry, $tripped);

        return $tripped === [] && ! $reconcileTripped ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Phase 3: the accounts of a token several accounts post with that its
     * holder can no longer post as (ListsReachableAccounts). Each such token
     * still in use is listed once, with its stored token: one expired (left
     * to the renewal) or unreadable is skipped, and a list that cannot be read
     * whole decides nothing. A missing account is flagged with the reason a
     * reconnect gives, as a reconnect would, once a second read of the list
     * confirms it (a one-off answer leaving it out decides nothing) and the
     * connector, asked about that account itself, says it is out of reach
     * (isStillReachable(): an Instagram account reached through Business
     * Manager is not on the list, yet posts).
     *
     * A connector whose list is the only sign (no VerifiesMissingAccounts:
     * LinkedIn) gets no verdict in the run that first finds an account
     * missing: the run notes when, in the cache, and a later run flags it if
     * it is still missing at least MISSING_FOR_SECONDS after. An account back
     * on the list, a new token, or a cache that lost the note (cleared, or
     * no run within CONFIRM_WITHIN_SECONDS) starts it over: a lost note only
     * ever delays a verdict. A provider incident serving short lists for a few
     * hours, which two reads a second apart would both see, cuts nobody off,
     * even on an install too small for the breaker.
     *
     * Nothing is decided for an account provider when phase 1 found its
     * credentials' provider in an incident ($trippedProviders), when too many
     * of its lists could not be read (an outage letting a few short lists
     * through), or when too many holders lost accounts at once. Holders, not
     * accounts: a member who loses fifteen organizations at once is one
     * holder, whose loss must not hold up everyone else's every day.
     *
     * @param  array<string, true>  $trippedProviders  credential providers phase 1's breaker stopped
     * @return bool Whether a breaker stopped a provider's verdicts.
     */
    protected function reconcileAccounts(ConnectorRegistry $registry, array $trippedProviders = []): bool
    {
        if (! Settings::reconcileAccounts()) {
            return false;
        }

        /** @var array<string, ListsReachableAccounts> $connectors */
        $connectors = [];

        foreach ($registry->configured() as $provider) {
            $connector = $registry->for($provider);

            if ($connector instanceof ListsReachableAccounts && ! isset($trippedProviders[$provider])) {
                $connectors[$provider] = $connector;
            }
        }

        if ($connectors === []) {
            return false;
        }

        // Per account provider: lists tried, lists that could not be read,
        // holders with accounts confirmed missing, accounts listed against.
        /** @var array<string, int> $lists */
        $lists = [];
        /** @var array<string, int> $unreadable */
        $unreadable = [];
        /** @var array<string, int> $holders */
        $holders = [];
        /** @var array<string, int> $accountsChecked */
        $accountsChecked = [];
        /** @var array<string, string|null> $reasons */
        $reasons = [];

        /** @var array<string, list<array{account: SocialAccount, reason: string, cipher: mixed}>> $missing */
        $missing = [];

        /** @var list<array{key: string, providers: array<int, string>, since: array<int|string, int>, noted: bool}> $notes */
        $notes = [];

        SocialToken::query()
            ->whereIn('provider', array_keys($connectors))
            ->where('status', AccountStatus::Active->value)
            ->lazyById()
            ->each(function (SocialToken $token) use ($connectors, &$lists, &$unreadable, &$holders, &$accountsChecked, &$reasons, &$missing, &$notes) {
                $connector = $connectors[$token->provider];

                // Never the holder's own account (a LinkedIn member's
                // profile), whatever its row says about its holder.
                $accounts = $token->accounts()
                    ->where('status', AccountStatus::Active->value)
                    ->whereIn('provider', $connector->reachableAccountProviders())
                    ->when($token->provider_holder_id !== null, fn ($query) => $query->where('provider_user_id', '!=', $token->provider_holder_id))
                    ->where(fn ($query) => $query->whereNull('provider_holder_id')->orWhereColumn('provider_user_id', '!=', 'provider_holder_id'))
                    ->get();

                if ($accounts->isEmpty() || ! $token->tokensDecrypt() || $token->isAccessTokenExpired()) {
                    return;
                }

                $cipher = $token->getRawOriginal('access_token');
                $providers = $accounts->pluck('provider')->unique()->all();

                foreach ($providers as $provider) {
                    $lists[$provider] = ($lists[$provider] ?? 0) + 1;
                }

                $ids = $this->reachableAccountIds($connector, $token);
                $absent = $ids instanceof RenewalResult ? $accounts : $accounts->reject(fn (SocialAccount $account) => in_array((string) $account->provider_user_id, $ids, true));

                // A missing account is read again before anything is decided:
                // one answer leaving it out could be a fluke.
                if (! $ids instanceof RenewalResult && $absent->isNotEmpty()) {
                    $ids = $this->reachableAccountIds($connector, $token);
                    $absent = $ids instanceof RenewalResult ? $absent : $absent->reject(fn (SocialAccount $account) => in_array((string) $account->provider_user_id, $ids, true));
                }

                if ($ids instanceof RenewalResult) {
                    foreach ($providers as $provider) {
                        $unreadable[$provider] = ($unreadable[$provider] ?? 0) + 1;
                        $reasons[$provider] ??= $ids->reason;
                    }

                    return;
                }

                // Asked about itself: an account the list leaves out may still
                // be reached another way. Only a "no" flags it.
                $absent = $absent->filter(fn (SocialAccount $account) => $this->stillReachable($connector, $token, $account) === false);

                foreach ($accounts as $account) {
                    $accountsChecked[$account->provider] = ($accountsChecked[$account->provider] ?? 0) + 1;
                }

                foreach ($absent->pluck('provider')->unique() as $provider) {
                    $holders[$provider] = ($holders[$provider] ?? 0) + 1;
                }

                // The list alone: flagged once missing for a day.
                if (! $connector instanceof VerifiesMissingAccounts) {
                    [$absent, $notes[]] = $this->missingForADay($token, $cipher, $absent, $providers);
                }

                foreach ($absent as $account) {
                    $missing[$account->provider][] = ['account' => $account, 'reason' => $connector->unreachableAccountReason(), 'cipher' => $cipher];
                }
            });

        ['min_checked' => $minChecked, 'max_terminal_ratio' => $maxRatio] = Settings::staticCheckBreaker();
        $tripped = false;
        $flagged = 0;

        /** @var array<string, true> $stopped */
        $stopped = [];

        foreach ($lists as $provider => $tried) {
            $provider = (string) $provider;
            $failed = $unreadable[$provider] ?? 0;
            $losing = $holders[$provider] ?? 0;

            if ($failed > 0) {
                Log::warning("[social-tokens] check-static could not list the accounts behind {$failed} credential(s) of {$provider} accounts; none of them was judged.", [
                    'provider' => $provider,
                    'credentials' => $failed,
                    'reason' => $reasons[$provider] ?? null,
                ]);
            }

            // An outage letting a few short lists through, or too many
            // holders losing accounts at once: nobody is judged.
            if ($this->tripsBreaker($tried, $failed, $minChecked, $maxRatio)
                || $this->tripsBreaker($tried - $failed, $losing, $minChecked, $maxRatio)) {
                $tripped = true;

                Log::critical("[social-tokens] check-static skipped reconciling {$provider}: too many of its lists failed, or too many holders lost accounts, at once", [
                    'provider' => $provider,
                    'lists' => $tried,
                    'unreadable' => $failed,
                    'holders_missing_accounts' => $losing,
                    'max_terminal_ratio' => $maxRatio,
                ]);

                $this->error("Aborted reconciling {$provider}: {$failed} of {$tried} lists failed and {$losing} holders lost accounts at once; none of them was flagged.");
                $stopped[$provider] = true;

                continue;
            }

            foreach ($missing[$provider] ?? [] as ['account' => $account, 'reason' => $reason, 'cipher' => $cipher]) {
                try {
                    $flagged += $this->flagAccount($account, $reason, $cipher) ? 1 : 0;
                } catch (Throwable $e) {
                    Log::error('[social-tokens] Could not record an account check', [
                        'account_id' => $account->getKey(),
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }

        $waiting = $this->keepNotes($notes, $stopped);
        $checked = array_sum($accountsChecked);

        if ($checked > 0) {
            $credentials = array_sum($lists) - array_sum($unreadable);
            $this->info("Checked {$checked} account(s) behind {$credentials} shared credential(s), flagged {$flagged}.");
        }

        if ($waiting > 0) {
            $this->line("{$waiting} account(s) missing from their list for less than a day: flagged if still missing then.");
        }

        return $tripped;
    }

    /**
     * Of the accounts missing from a list that is the only sign, those
     * missing for at least MISSING_FOR_SECONDS (since a run that found them
     * missing, as noted in the cache). Returns them, and the note this run
     * leaves: the time each other missing account was first found missing,
     * now for those just found. Accounts back on the list drop out of it. The
     * note is keyed on the credential and its token: a reconnect's new token
     * starts over.
     *
     * @param  Collection<int, SocialAccount>  $absent
     * @param  array<int, string>  $providers
     * @return array{0: Collection<int, SocialAccount>, 1: array{key: string, providers: array<int, string>, since: array<int|string, int>, noted: bool}}
     */
    protected function missingForADay(SocialToken $token, mixed $cipher, Collection $absent, array $providers): array
    {
        $key = 'social-tokens:reconcile-missing:'.$token->getKey().':'.substr(hash('sha256', is_string($cipher) ? $cipher : ''), 0, 16);

        try {
            $noted = Cache::get($key);
        } catch (Throwable) {
            $noted = null; // a cache it cannot read flags nothing today
        }

        $noted = is_array($noted) ? $noted : [];
        $now = now()->getTimestamp();
        $since = [];

        $due = $absent->filter(function (SocialAccount $account) use ($noted, $now, &$since) {
            $first = $noted[$account->getKey()] ?? null;

            if (is_int($first) && $first <= $now && $now - $first >= self::MISSING_FOR_SECONDS) {
                return true;
            }

            $since[$account->getKey()] = is_int($first) && $first <= $now ? $first : $now;

            return false;
        });

        return [$due->values(), ['key' => $key, 'providers' => $providers, 'since' => $since, 'noted' => $noted !== []]];
    }

    /**
     * Store the notes of this run, except for providers a breaker stopped
     * (an incident must not start anyone's day). Returns how many accounts
     * wait.
     *
     * @param  list<array{key: string, providers: array<int, string>, since: array<int|string, int>, noted: bool}>  $notes
     * @param  array<string, true>  $stopped
     */
    protected function keepNotes(array $notes, array $stopped): int
    {
        $waiting = 0;

        foreach ($notes as $note) {
            if (array_intersect($note['providers'], array_keys($stopped)) !== []) {
                continue;
            }

            try {
                if ($note['since'] !== []) {
                    Cache::put($note['key'], $note['since'], now()->addSeconds(self::CONFIRM_WITHIN_SECONDS));
                    $waiting += count($note['since']);
                } elseif ($note['noted']) {
                    Cache::forget($note['key']);
                }
            } catch (Throwable $e) {
                Log::warning('[social-tokens] check-static could not note accounts missing from their list; they wait another day.', [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $waiting;
    }

    /**
     * Flag an account its holder no longer reaches, only while it still posts
     * with the credential that was listed, and that credential still holds
     * the token that was listed: a reconnect during the run (onto another
     * credential, or onto the same one with a new token) wins.
     */
    protected function flagAccount(SocialAccount $account, string $reason, mixed $cipher): bool
    {
        $current = SocialAccount::query()
            ->whereKey($account->getKey())
            ->where('status', AccountStatus::Active->value)
            ->where('social_token_id', $account->social_token_id)
            ->first();

        $sameToken = SocialToken::query()
            ->whereKey($account->social_token_id)
            ->where('access_token', $cipher)
            ->exists();

        if ($current === null || ! $sameToken) {
            return false;
        }

        $current->markNeedsReconnect($reason);

        return $current->status === AccountStatus::NeedsReconnect;
    }

    /** The connector's answer about one account, an exception taken as no answer. */
    protected function stillReachable(ListsReachableAccounts $connector, SocialToken $token, SocialAccount $account): ?bool
    {
        try {
            return $connector->isStillReachable($token, $account);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The connector's list, an exception taken as a list that cannot be read.
     *
     * @return array<int, string>|RenewalResult
     */
    protected function reachableAccountIds(ListsReachableAccounts $connector, SocialToken $token): array|RenewalResult
    {
        try {
            return $connector->reachableAccountIds($token);
        } catch (Throwable $e) {
            return RenewalResult::unknownFailure('Unexpected error: '.$e::class);
        }
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
