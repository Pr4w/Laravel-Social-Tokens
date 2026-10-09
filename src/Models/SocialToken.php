<?php

namespace Pr4w\SocialTokens\Models;

use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Pr4w\SocialTokens\Connectors\AbstractConnector;
use Pr4w\SocialTokens\Contracts\ProviderConnector;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Events\CredentialDataAccessExpiring;
use Pr4w\SocialTokens\Events\CredentialExpiringSoon;
use Pr4w\SocialTokens\Events\CredentialNeedsReconnect;
use Pr4w\SocialTokens\Events\CredentialRenewed;
use Pr4w\SocialTokens\Events\CredentialRevoked;
use Pr4w\SocialTokens\Models\Concerns\TransitionsStatus;
use Pr4w\SocialTokens\Support\RenewalResult;
use Pr4w\SocialTokens\Support\Settings;

/**
 * The renewable credential. One credential can back many accounts (a Meta user
 * token backs every Instagram account of a Facebook user; a LinkedIn member token
 * backs every organization). Renewal happens here, once, not per account. Each
 * Facebook Page posts with its own static page-token credential.
 *
 * A credential is unique per (provider, holder, purpose, owner). The purpose
 * keeps apart the grants one holder gives the same owner through different
 * flows, so that one flow never replaces the other's token: '' for the
 * default credential, StoreLinkedInOrganizations::PURPOSE for the LinkedIn
 * member token behind the organizations (the member's personal profile has
 * the default one).
 *
 * @property string $provider
 * @property ?string $provider_holder_id
 * @property string $purpose
 * @property ?string $access_token
 * @property ?string $refresh_token
 * @property ?CarbonInterface $expires_at
 * @property ?CarbonInterface $refresh_expires_at
 * @property ?CarbonInterface $renew_at
 * @property ?CarbonInterface $last_renewed_at
 * @property ?CarbonInterface $data_access_expires_at
 * @property ?CarbonInterface $data_access_warned_at
 * @property array<int, string>|null $scopes
 * @property ?string $last_error
 * @property int $failed_checks
 * @property ?string $ownable_type
 * @property int|string|null $ownable_id
 * @property AccountStatus $status
 */
class SocialToken extends Model
{
    use TransitionsStatus;

    /** The purpose of a holder's default credential. */
    public const DEFAULT_PURPOSE = '';

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = ['purpose' => self::DEFAULT_PURPOSE];

    /**
     * Never serialised (toArray/toJson, e.g. an account loaded with its
     * credential). Still readable as attributes; makeVisible() opts back in.
     *
     * @var list<string>
     */
    protected $hidden = ['access_token', 'refresh_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'refresh_expires_at' => 'datetime',
            'renew_at' => 'datetime',
            'last_renewed_at' => 'datetime',
            'data_access_expires_at' => 'datetime',
            'data_access_warned_at' => 'datetime',
            'scopes' => 'array',
            'status' => AccountStatus::class,
        ];
    }

    public function getTable()
    {
        return config('social-tokens.tokens_table', 'social_tokens');
    }

    protected static function booted(): void
    {
        // A new data access deadline (a new login) re-arms its warning,
        // whichever writer stores it.
        static::saving(function (SocialToken $token) {
            if ($token->isDirty('data_access_expires_at') && ! $token->isDirty('data_access_warned_at')) {
                $token->data_access_warned_at = null;
            }
        });
    }

    // Relationships ---------------------------------------------------------

    /**
     * The owner of the grant (2.0): each owner keeps its own credential, even
     * for the same external holder.
     *
     * @return MorphTo<Model, $this>
     */
    public function ownable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<SocialAccount, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    // Scopes ----------------------------------------------------------------

    /**
     * Credentials of one owner (or the owner-less ones for null).
     *
     * @param  Builder<SocialToken>  $query
     * @return Builder<SocialToken>
     */
    public function scopeOwnedBy(Builder $query, ?Model $owner): Builder
    {
        return $owner === null
            ? $query->whereNull('ownable_type')->whereNull('ownable_id')
            : $query->whereMorphedTo('ownable', $owner);
    }

    /**
     * Credentials of one purpose: the default ones unless told otherwise.
     *
     * @param  Builder<SocialToken>  $query
     * @return Builder<SocialToken>
     */
    public function scopeForPurpose(Builder $query, string $purpose = self::DEFAULT_PURPOSE): Builder
    {
        return $query->where('purpose', $purpose);
    }

    /**
     * Credentials whose renewal window has opened.
     *
     * @param  Builder<SocialToken>  $query
     * @return Builder<SocialToken>
     */
    public function scopeDueForRenewal(Builder $query): Builder
    {
        return $query
            ->where('status', AccountStatus::Active->value)
            ->whereNotNull('renew_at')
            ->where('renew_at', '<=', now());
    }

    /**
     * Credentials whose data access (Meta's data_access_expires_at) lapses
     * before $before and that have not been warned about it, among those an
     * active account posts with: the Instagram accounts' Meta user token, not
     * the one behind a Facebook-only connection, whose Pages post with their
     * own tokens.
     *
     * @param  Builder<SocialToken>  $query
     * @return Builder<SocialToken>
     */
    public function scopeDataAccessExpiringBefore(Builder $query, CarbonInterface $before): Builder
    {
        return $query
            ->where('status', AccountStatus::Active->value)
            ->whereNotNull('data_access_expires_at')
            ->where('data_access_expires_at', '<=', $before)
            ->whereNull('data_access_warned_at')
            ->whereHas('accounts', fn (Builder $account) => $account->where('status', AccountStatus::Active->value));
    }

    /**
     * Credentials some active account still depends on: through social_token_id
     * (the account posts with it), or as the holder of accounts of the same
     * provider (the Meta user token behind a user's Facebook Pages, which post
     * with their own page tokens but are re-derived from it).
     *
     * An account posting with another credential of the same holder keeps
     * only that one alive: the LinkedIn organizations their own credential,
     * not the member's personal one beside it, and the other way round.
     *
     * @param  Builder<SocialToken>  $query
     * @return Builder<SocialToken>
     */
    public function scopeInUse(Builder $query): Builder
    {
        $tokens = $this->getTable();
        $accounts = (new SocialAccount)->getTable();
        $active = AccountStatus::Active->value;

        return $query->where(fn (Builder $query) => $query
            ->whereHas('accounts', fn (Builder $account) => $account->where('status', $active))
            ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from($accounts)
                ->whereColumn("{$accounts}.provider", "{$tokens}.provider")
                ->whereColumn("{$accounts}.provider_holder_id", "{$tokens}.provider_holder_id")
                ->where("{$accounts}.status", $active)
                // Same owner (null-safe): another owner's Pages keep their own credential alive.
                ->where(fn ($owner) => $owner
                    ->where(fn ($same) => $same
                        ->whereColumn("{$accounts}.ownable_type", "{$tokens}.ownable_type")
                        ->whereColumn("{$accounts}.ownable_id", "{$tokens}.ownable_id"))
                    ->orWhere(fn ($none) => $none
                        ->whereNull("{$accounts}.ownable_type")
                        ->whereNull("{$tokens}.ownable_type")))
                ->whereNotExists(fn ($sibling) => $sibling->selectRaw('1')->from("{$tokens} as sibling_credential")
                    ->whereColumn('sibling_credential.id', "{$accounts}.social_token_id")
                    ->whereColumn('sibling_credential.provider', "{$tokens}.provider")
                    ->whereColumn('sibling_credential.provider_holder_id', "{$tokens}.provider_holder_id"))));
    }

    /**
     * Credentials nothing will use again, unchanged since $before: revoked or
     * flagged needs_reconnect ones (only a reconnect, which stores a token of
     * its own, brings those back), and those no connected (not revoked)
     * account depends on, by social_token_id or as its holder under the same
     * owner (the Meta user token behind a user's Pages). The holder test is
     * looser than inUse()'s: a credential is kept while any connected account
     * of its provider, holder and owner exists.
     *
     * @param  Builder<SocialToken>  $query
     * @return Builder<SocialToken>
     */
    public function scopePrunable(Builder $query, CarbonInterface $before): Builder
    {
        $tokens = $this->getTable();
        $accounts = (new SocialAccount)->getTable();
        $revoked = AccountStatus::Revoked->value;

        return $query
            ->where('updated_at', '<', $before)
            ->where(fn (Builder $query) => $query
                ->whereIn('status', [$revoked, AccountStatus::NeedsReconnect->value])
                ->orWhere(fn (Builder $unused) => $unused
                    ->whereDoesntHave('accounts', fn (Builder $account) => $account->where('status', '!=', $revoked))
                    ->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from($accounts)
                        ->whereColumn("{$accounts}.provider", "{$tokens}.provider")
                        ->whereColumn("{$accounts}.provider_holder_id", "{$tokens}.provider_holder_id")
                        ->where("{$accounts}.status", '!=', $revoked)
                        // Same owner (null-safe), as in inUse().
                        ->where(fn ($owner) => $owner
                            ->where(fn ($same) => $same
                                ->whereColumn("{$accounts}.ownable_type", "{$tokens}.ownable_type")
                                ->whereColumn("{$accounts}.ownable_id", "{$tokens}.ownable_id"))
                            ->orWhere(fn ($none) => $none
                                ->whereNull("{$accounts}.ownable_type")
                                ->whereNull("{$tokens}.ownable_type"))))));
    }

    // Persistence -------------------------------------------------------------

    /**
     * Store a token the provider has just issued on the row identified by
     * $keys, created when missing. Unless $values says otherwise, the row is
     * left in its freshly issued state (see issuedState()).
     *
     * updateOrCreate() without handing Eloquent the plaintext: its query frames
     * (updateOrCreate, firstOrCreate, createOrFirst, create) would record the
     * tokens in the trace of a failed write (connection lost, deadlock, disk
     * error), where an error tracker collects them. Here the encrypted cast
     * applies in forceFill(), which has returned before the write starts, so
     * those frames only ever see ciphertext.
     *
     * A row another request created under the same keys meanwhile is updated
     * instead, as createOrFirst() would.
     *
     * With $connector, and no renew_at in $values, renew_at is scheduled from
     * the stored expiries with the strategy of the credential as it will be
     * stored (renewAtFor(), renewalStrategyWith()): what it holds can change
     * how it renews (a LinkedIn credential without a refresh token).
     *
     * $keys without a purpose name the default credential.
     *
     * @param  array<string, mixed>  $keys
     * @param  array<string, mixed>  $values
     */
    public static function persistCredential(array $keys, #[\SensitiveParameter] array $values, ?ProviderConnector $connector = null): static
    {
        $keys += ['purpose' => static::DEFAULT_PURPOSE];
        $values += static::issuedState();

        $token = static::query()->firstOrNew($keys)->forceFill($values);

        if ($connector !== null && ! array_key_exists('renew_at', $values)) {
            $values['renew_at'] = $token->renew_at = static::renewAtFor(
                $token->expires_at,
                $connector,
                $token->refresh_expires_at,
                $token->renewalStrategyWith($connector),
            );
        }

        try {
            // A savepoint inside the caller's transaction: on PostgreSQL a failed
            // insert would otherwise abort it, and the lookup below with it.
            static::query()->withSavepointIfNeeded(fn () => $token->save());
        } catch (UniqueConstraintViolationException $e) {
            $token = static::query()->useWritePdo()->where($keys)->first() ?? throw $e;
            $token->forceFill($values)->save();
        }

        return $token;
    }

    /**
     * The state of a credential whose token the provider has just issued or
     * accepted (a connection, a successful renewal), in one place so that no
     * writer forgets a part: active, no error, the token's age counted from
     * now, and no check-static strike. A strike judges one token; carried over,
     * the previous token's would let the new one be flagged on its first
     * unconfirmed rejection.
     *
     * @return array<string, mixed>
     */
    protected static function issuedState(): array
    {
        return [
            'status' => AccountStatus::Active,
            'last_error' => null,
            'last_renewed_at' => now(),
            'failed_checks' => 0,
        ];
    }

    // Scopes ------------------------------------------------------------------

    /**
     * The one shape for stored scopes: one scope per string, no blanks, no
     * duplicates, order kept. An element holding several scopes is split on
     * commas and whitespace: LinkedIn and TikTok send theirs comma-separated,
     * and Socialite's LinkedIn drivers split them on spaces, which leaves them
     * joined. An empty result is null: "unknown", which never overwrites a
     * known list (a known-empty list comes from the provider).
     *
     * @param  array<int, mixed>|null  $scopes
     * @return array<int, string>|null
     */
    public static function normaliseScopes(?array $scopes): ?array
    {
        $split = [];

        foreach ($scopes ?? [] as $scope) {
            if (is_string($scope)) {
                array_push($split, ...(preg_split('/[\s,]+/', $scope, -1, PREG_SPLIT_NO_EMPTY) ?: []));
            }
        }

        $split = array_values(array_unique($split));

        return $split === [] ? null : $split;
    }

    /** Whether the granted scopes are known (null means unknown, [] means none granted). */
    public function scopesKnown(): bool
    {
        return is_array($this->scopes);
    }

    // Scheduling --------------------------------------------------------------

    /**
     * When to renew a credential, the one rule used at connect time and after
     * each renewal: one lead time before whichever comes first, the access
     * token's expiry or the refresh token's (so the user is warned before the
     * refresh token dies). An unknown expiry on a renewable credential is
     * checked back after one lead time, never left static; a credential that
     * cannot renew unattended with an unknown expiry stays unscheduled.
     *
     * $strategy is the credential's own (renewalStrategyWith()), when it may
     * differ from the connector's renewalStrategy().
     */
    public static function renewAtFor(
        ?CarbonInterface $expiresAt,
        ?ProviderConnector $connector,
        ?CarbonInterface $refreshExpiresAt = null,
        ?RenewalStrategy $strategy = null,
    ): ?CarbonInterface {
        if ($connector === null) {
            return null;
        }

        $lead = $connector->leadTime();

        if ($expiresAt !== null) {
            $renewAt = $expiresAt->copy()->sub($lead);
        } elseif (($strategy ?? $connector->renewalStrategy())->canRenewUnattended()) {
            $renewAt = now()->add($lead);
        } else {
            return null;
        }

        if ($refreshExpiresAt !== null && $refreshExpiresAt->copy()->sub($lead)->lessThan($renewAt)) {
            $renewAt = $refreshExpiresAt->copy()->sub($lead);
        }

        return $renewAt;
    }

    /**
     * How this credential is kept alive: the connector's strategy, narrowed to
     * this credential where the connector does so
     * (AbstractConnector::renewalStrategyFor()).
     */
    public function renewalStrategyWith(ProviderConnector $connector): RenewalStrategy
    {
        return $connector instanceof AbstractConnector
            ? $connector->renewalStrategyFor($this)
            : $connector->renewalStrategy();
    }

    // State -----------------------------------------------------------------

    public function isAccessTokenExpired(int $bufferSeconds = 30): bool
    {
        return $this->expires_at !== null
            && $this->expires_at->lessThanOrEqualTo(now()->addSeconds($bufferSeconds));
    }

    /**
     * The renewal window has opened (same rule as the dueForRenewal scope). A
     * token can be due well before it expires: that is the point of the lead time.
     */
    public function isDueForRenewal(): bool
    {
        return $this->renew_at !== null && ! $this->renew_at->isFuture();
    }

    public function isRefreshTokenExpired(): bool
    {
        return $this->refresh_expires_at !== null && $this->refresh_expires_at->isPast();
    }

    /**
     * The provider has stopped sharing the user's data with the app (Meta's
     * data access, 90 days after the user was last active), although the token
     * may still work. Only a new login brings it back. False when unknown.
     */
    public function isDataAccessExpired(): bool
    {
        return $this->data_access_expires_at !== null && ! $this->data_access_expires_at->isFuture();
    }

    /**
     * Fire CredentialDataAccessExpiring for the stored deadline, once: only
     * the call that records the warning fires it. A new deadline stored later
     * (a new login) re-arms it. Returns whether this call warned.
     */
    public function warnDataAccessExpiring(): bool
    {
        $deadline = $this->data_access_expires_at;

        if (! $this->exists || $deadline === null) {
            return false;
        }

        $warnedAt = now();

        $changed = $this->newQuery()
            ->whereKey($this->getKey())
            ->whereNull('data_access_warned_at')
            ->where('data_access_expires_at', $this->getRawOriginal('data_access_expires_at'))
            ->update(['data_access_warned_at' => $this->fromDateTime($warnedAt)]) > 0;

        if ($changed) {
            $this->forceFill(['data_access_warned_at' => $warnedAt])->syncOriginalAttribute('data_access_warned_at');

            event(new CredentialDataAccessExpiring($this, $deadline));
        }

        return $changed;
    }

    /** The refresh token still works but dies within $lead: time to warn the user. */
    public function isRefreshTokenExpiring(CarbonInterval $lead): bool
    {
        return $this->refresh_expires_at !== null
            && ! $this->isRefreshTokenExpired()
            && $this->refresh_expires_at->copy()->sub($lead)->lessThanOrEqualTo(now());
    }

    /**
     * refresh() through a locking read (SELECT ... FOR UPDATE), which returns
     * the row as last committed and waits for a write still in flight. A plain
     * read inside the caller's transaction can return an older snapshot
     * (MySQL's default REPEATABLE READ), or miss a renewal another process has
     * written but not committed yet: renewing from such a copy would spend a
     * single-use refresh token the provider already rotated. Inside a
     * transaction the row stays locked until it ends; outside one, only for
     * the read. SQLite has no row locks and reads as usual.
     *
     * @internal
     *
     * @throws ModelNotFoundException<SocialToken> when the row is gone
     * @throws QueryException when the database gave up waiting for the row lock
     *                        (a lock wait timeout, a deadlock), among others
     */
    public function refreshForUpdate(): static
    {
        $current = $this->newQueryWithoutScopes()
            ->useWritePdo()
            ->whereKey($this->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $this->setRawAttributes($current->getAttributes(), true);
        $this->load(array_keys($this->getRelations()));

        return $this;
    }

    /**
     * Apply a successful credential refresh and recompute the renewal window.
     * Nothing is written when the credential was revoked meanwhile, or no
     * longer holds the tokens this instance was loaded with (see
     * tryApplyRenewal()).
     */
    public function applyRenewal(RenewalResult $result, ProviderConnector $connector): self
    {
        $this->tryApplyRenewal($result, $connector);

        return $this;
    }

    /**
     * applyRenewal() that says whether the renewal was stored. It is stored
     * only on the token it renewed: the access and refresh tokens this
     * instance was loaded with, compared by ciphertext under a row lock. A
     * reconnect that replaced them while the provider call was in flight wins
     * (the renewal of the old grant would bring back its tokens and lose the
     * new grant's), and so does a revoke. Either way nothing is written, no
     * CredentialRenewed fires, and the instance is reloaded with the row as
     * it now is. Load the credential right before renewing it.
     *
     * @throws ModelNotFoundException<SocialToken> when the row was deleted meanwhile
     */
    public function tryApplyRenewal(RenewalResult $result, ProviderConnector $connector): bool
    {
        // "Not extended" is judged on the access token alone: renew_at may
        // legitimately land earlier because of the refresh token.
        $notExtended = $result->expiresAt !== null && ! $result->expiresAt->copy()->sub($connector->leadTime())->isFuture();

        if ($this->exists) {
            $held = [$this->getRawOriginal('access_token'), $this->getRawOriginal('refresh_token')];

            // The lock keeps a reconnect or a revoke from landing between the
            // comparison and the write: it waits, and is written last.
            $stored = $this->getConnection()->transaction(function () use ($held, $result, $connector, $notExtended) {
                $current = $this->newQueryWithoutScopes()
                    ->useWritePdo()
                    ->whereKey($this->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($current->status === AccountStatus::Revoked
                    || [$current->getRawOriginal('access_token'), $current->getRawOriginal('refresh_token')] !== $held) {
                    $this->setRawAttributes($current->getAttributes(), true);

                    return false;
                }

                // A flag that landed meanwhile is lifted: the provider has just
                // accepted the refresh token.
                $this->status = $current->status;
                $this->syncOriginalAttribute('status');

                return $this->storeRenewal($result, $connector, $notExtended);
            });
        } else {
            $stored = $this->status !== AccountStatus::Revoked && $this->storeRenewal($result, $connector, $notExtended);
        }

        if ($stored) {
            $this->announceRenewal($result, $connector, $notExtended);
        }

        return $stored;
    }

    /**
     * Write the renewed token and its new window, on a credential known to
     * still hold the token that was renewed.
     */
    protected function storeRenewal(RenewalResult $result, ProviderConnector $connector, bool $notExtended): bool
    {
        $this->access_token = $result->accessToken;

        // Only overwrite the refresh token when the provider issued a new one.
        if ($result->refreshToken !== null) {
            $this->refresh_token = $result->refreshToken;
        }

        // Before renew_at: a refresh token nearing its end pulls renewal earlier.
        if ($result->refreshExpiresAt !== null) {
            $this->refresh_expires_at = $result->refreshExpiresAt;
        }

        if ($result->expiresAt !== null) {
            $this->expires_at = $result->expiresAt;

            // The provider did not push the expiry past the lead time (e.g.
            // Meta returning the remaining lifetime): renewing again on every
            // tick would change nothing. Land the next pass at the expiry,
            // where the token is flagged if nobody reconnected.
            $this->renew_at = $notExtended
                ? $result->expiresAt->copy()
                : static::renewAtFor($result->expiresAt, $connector, $this->refresh_expires_at, $this->renewalStrategyWith($connector));
        } elseif ($result->neverExpires) {
            // The provider says the token does not expire (a Meta user token
            // from some Facebook Login configurations): nothing to renew, short
            // of a refresh token's own end. Static, so check-static keeps an
            // eye on it.
            $this->expires_at = null;
            $this->renew_at = $this->refresh_expires_at?->copy()->sub($connector->leadTime());
        } else {
            // A renewable credential came back without an expiry. Keeping the
            // old expires_at would be stale, and a null renew_at would turn it
            // static and never renew it again — so mark the expiry unknown and
            // check back after one lead time.
            $this->expires_at = null;
            $this->renew_at = static::renewAtFor(null, $connector, $this->refresh_expires_at, $this->renewalStrategyWith($connector))
                ?? now()->add($connector->leadTime());
        }

        if ($result->dataAccessExpiresAt !== null) {
            $this->data_access_expires_at = $result->dataAccessExpiresAt;
        }

        // Providers that echo the granted scopes (TikTok and LinkedIn use commas,
        // Google spaces): keep the credential's list current.
        if (is_string($result->profile['scope'] ?? null)) {
            $scopes = static::normaliseScopes([$result->profile['scope']]);

            if ($scopes !== null) {
                $this->scopes = $scopes;
            }
        }

        return $this->forceFill(static::issuedState())->save();
    }

    /** Events and logs of a stored renewal, once its write is committed or joined the caller's transaction. */
    protected function announceRenewal(RenewalResult $result, ProviderConnector $connector, bool $notExtended): void
    {
        if ($result->expiresAt === null && ! $result->neverExpires && Settings::logUnknownErrors()) {
            Log::warning('[social-tokens] Renewal returned no expiry', [
                'provider' => $this->provider,
                'token_id' => $this->getKey(),
            ]);
        }

        event(new CredentialRenewed($this));

        $strategy = $this->renewalStrategyWith($connector);

        if ($result->expiresAt !== null && $strategy === RenewalStrategy::ExtendLongLived) {
            // Record what the provider granted: whether Meta really extends a
            // still-valid long-lived token is not documented.
            Log::info('[social-tokens] Long-lived token extended', [
                'provider' => $this->provider,
                'token_id' => $this->getKey(),
                'expires_in' => (int) now()->diffInSeconds($result->expiresAt, false),
            ]);
        }

        if ($notExtended && $result->expiresAt !== null) {
            Log::warning('[social-tokens] Renewal did not extend the credential past its lead time', [
                'provider' => $this->provider,
                'token_id' => $this->getKey(),
                'expires_in' => (int) now()->diffInSeconds($result->expiresAt, false),
                'expires_at' => $result->expiresAt->toIso8601String(),
            ]);

            // Nothing renews a long-lived token that will not extend: the user
            // has to reconnect before it expires. Refresh-token providers will
            // still refresh at the expiry, so they get no warning.
            if ($strategy === RenewalStrategy::ExtendLongLived) {
                event(new CredentialExpiringSoon($this, $result->expiresAt, 'Provider did not extend the token.'));
            }
        }
    }

    /**
     * One-way and idempotent: only an active credential moves to
     * needs_reconnect, and only the call that actually moves it fires the event
     * and records its reason (the root cause; later callers change nothing). A
     * revoked credential is never downgraded. Writes only the status and
     * last_error: save() any other change yourself.
     */
    public function markNeedsReconnect(?string $reason = null): self
    {
        $this->markNeedsReconnectOnce($reason);

        return $this;
    }

    /**
     * markNeedsReconnect() that says whether THIS call flagged the credential,
     * and can be limited to a given token: with $checkedToken, the credential
     * is flagged only if it still holds that token (a rejection of a token a
     * reconnect already replaced says nothing about the new one).
     */
    public function markNeedsReconnectOnce(?string $reason = null, #[\SensitiveParameter] ?string $checkedToken = null): bool
    {
        if ($checkedToken !== null && $this->exists) {
            // Tokens are encrypted with a random IV: compare the decrypted value
            // of the current row, then pin the update to that exact ciphertext.
            $current = $this->newQuery()->whereKey($this->getKey())->first();

            if ($current === null || ! hash_equals((string) $current->access_token, $checkedToken)) {
                if ($current !== null) {
                    $this->status = $current->status;
                    $this->syncOriginalAttribute('status');
                }

                return false;
            }

            $changed = $this->newQuery()
                ->whereKey($this->getKey())
                ->where('status', AccountStatus::Active->value)
                ->where('access_token', $current->getRawOriginal('access_token'))
                ->update(['status' => AccountStatus::NeedsReconnect->value, 'last_error' => $reason]) > 0;

            if ($changed) {
                $this->forceFill(['status' => AccountStatus::NeedsReconnect, 'last_error' => $reason])
                    ->syncOriginalAttributes(['status', 'last_error']);
            }
        } else {
            $changed = $this->transitionStatus(AccountStatus::NeedsReconnect, [AccountStatus::Active], ['last_error' => $reason]);
        }

        if ($changed) {
            event(new CredentialNeedsReconnect($this, $reason));
        }

        return $changed;
    }

    /**
     * One-way and idempotent: fires CredentialRevoked only on the actual change.
     *
     * The tokens and their schedule are erased in the same write as the
     * status: nothing reads a revoked credential's tokens, and a reconnect
     * writes new ones. Kept, they were a working token the user asked to
     * remove (Meta, Threads and LinkedIn have no provider-side revocation,
     * and Google and TikTok skip it while another owner holds a grant), one
     * database leak plus APP_KEY away. Read the token before revoking if you
     * need it: SocialTokens::revoke() hands the provider a copy taken before.
     */
    public function markRevoked(): self
    {
        if ($this->transitionStatus(AccountStatus::Revoked, [AccountStatus::Active, AccountStatus::NeedsReconnect], static::revokedState())) {
            event(new CredentialRevoked($this));
        }

        return $this;
    }

    /**
     * What a revocation erases: the tokens, and the dates that describe them.
     *
     * @return array<string, null>
     */
    protected static function revokedState(): array
    {
        return [
            'access_token' => null,
            'refresh_token' => null,
            'expires_at' => null,
            'refresh_expires_at' => null,
            'renew_at' => null,
        ];
    }
}
