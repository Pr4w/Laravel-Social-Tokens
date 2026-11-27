<?php

namespace Pr4w\SocialTokens\Connectors;

use Carbon\Carbon;
use Carbon\CarbonInterval;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Pr4w\SocialTokens\Contracts\ChecksCredential;
use Pr4w\SocialTokens\Contracts\FetchesProfile;
use Pr4w\SocialTokens\Contracts\ListsReachableAccounts;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\AccountProfile;
use Pr4w\SocialTokens\Support\RenewalResult;
use Pr4w\SocialTokens\Support\Settings;

/**
 * LinkedIn publishing via the Posts API (part of the Community Management API).
 *
 * Token facts (verified against LinkedIn / Microsoft developer docs):
 *  - access token lives 60 days
 *  - refresh tokens are issued to approved partner programs (Community
 *    Management / Marketing Developer Platform), and live 365 days
 *  - the refresh token TTL does NOT reset on use: it counts down from issuance,
 *    so the member must re-authorise within a year regardless
 *
 * Scope note:
 *  - posting to a PERSONAL profile uses w_member_social
 *  - posting to a COMPANY page uses w_organization_social, restricted to members
 *    with a posting role on that page: only organizations held through one of
 *    the configured 'posting_roles' (default DEFAULT_POSTING_ROLES) are listed
 *  - scopes are chosen by the app at redirect (see README "Connecting an account")
 *
 * Strategy is config driven:
 *  - default ('refresh_enabled' => true): StableRefreshToken for every
 *    credential that holds a refresh token, ReauthOnly for the others
 *  - with 'refresh_enabled' => false: ReauthOnly, CredentialExpiringSoon fires
 *    ahead of the 60 day expiry; the credential keeps posting until then, and
 *    is flagged needs_reconnect only once it has actually expired
 */
class LinkedInConnector extends OAuth2Connector implements ChecksCredential, FetchesProfile, ListsReachableAccounts
{
    protected const TOKEN_URL = 'https://www.linkedin.com/oauth/v2/accessToken';

    /*
     * LinkedIn's versioned API: base path /rest, a LinkedIn-Version header on
     * every call. The unversioned /v2 Marketing APIs, organizationAcls among
     * them, were officially sunset in June 2023. /rest/organizationAcls lists
     * the same grants (checked in production on 2026-11-24) but refuses
     * projections: names and logos come from /rest/organizations, and the
     * logos' URLs from /rest/images.
     */
    protected const ORGANIZATION_ACLS_URL = 'https://api.linkedin.com/rest/organizationAcls';

    protected const ORGANIZATIONS_URL = 'https://api.linkedin.com/rest/organizations';

    protected const IMAGES_URL = 'https://api.linkedin.com/rest/images';

    /**
     * The LinkedIn-Version sent unless config `api_version` names another.
     * LinkedIn supports each version for at least a year, then answers 426:
     * the package's releases move it forward.
     */
    public const DEFAULT_API_VERSION = '202611';

    /** How old the configured version gets before a daily warning asks for a newer one. */
    protected const API_VERSION_WARNING_MONTHS = 11;

    /** How many organizations, or logos, one batch read asks for. */
    protected const BATCH_SIZE = 50;

    /**
     * What was logged today about the API version, so a run says it once.
     *
     * @var array<string, true>
     */
    private static array $notices = [];

    protected const INTROSPECT_URL = 'https://www.linkedin.com/oauth/v2/introspectToken';

    protected const USERINFO_URL = 'https://api.linkedin.com/v2/userinfo';

    /** The member's profile with r_basicprofile or r_liteprofile (Profile API). */
    protected const ME_URL = 'https://api.linkedin.com/v2/me';

    /** The member's name and picture, the picture's sizes decorated with their URLs. */
    protected const ME_PROJECTION = '(id,localizedFirstName,localizedLastName,profilePicture(displayImage~:playableStreams))';

    /** How long a member's organization list, read for their profiles, serves their other organizations. */
    protected const ORGANIZATION_LIST_SECONDS = 300;

    /**
     * Organization lists read for profiles, by member token, so a member's
     * organizations cost one listing per sync-profiles run, a failure
     * included. In memory only.
     *
     * @var array<string, array{at: int, organizations: array<int, array<string, mixed>>|RenewalResult}>
     */
    private array $organizationLists = [];

    protected const ORGANIZATION_PAGE_SIZE = 100;

    /**
     * @deprecated 3.8.0 No longer sent: /rest/organizationAcls refuses
     *             projections. Removed in 4.0.
     */
    public const ORGANIZATION_PROJECTION = '(paging,elements*(*,organization~(id,localizedName,logoV2(original~:playableStreams))))';

    /** Safety cap on organizationAcls result pages. */
    protected const MAX_RESULT_PAGES = 20;

    /** organizationAcls roles that can publish as the organization. */
    public const DEFAULT_POSTING_ROLES = [
        'ADMINISTRATOR',
        'CONTENT_ADMINISTRATOR',
        'DIRECT_SPONSORED_CONTENT_POSTER',
        'RECRUITING_POSTER',
    ];

    /**
     * Whether the member's personal profile and their organizations come from
     * one authorization (`single_authorization`): one LinkedIn app, usually
     * the Community Management one, asked for the scopes of both at once. They
     * then post with one token, stored on the member's default credential,
     * instead of a separate organizations credential (purpose 'organizations')
     * renewed on its own with the same refresh token. On by default, in a
     * published config without the option too.
     */
    public function singleAuthorization(): bool
    {
        return Settings::flag($this->config['single_authorization'] ?? true, true);
    }

    /**
     * The LinkedIn-Version of the /rest calls: config `api_version`
     * (LINKEDIN_API_VERSION) as YYYYMM, or DEFAULT_API_VERSION when it is
     * missing or malformed.
     */
    public function apiVersion(): string
    {
        $version = trim((string) ($this->config['api_version'] ?? ''));

        return preg_match('/^\d{4}(0[1-9]|1[0-2])(\.\d{2})?$/', $version) === 1 ? $version : self::DEFAULT_API_VERSION;
    }

    /**
     * `refresh_enabled` is read as every boolean setting (Settings::flag()):
     * LINKEDIN_REFRESH_ENABLED=off reaches the config as the string 'off'.
     */
    public function renewalStrategy(): RenewalStrategy
    {
        return Settings::flag($this->config['refresh_enabled'] ?? true, true)
            ? RenewalStrategy::StableRefreshToken
            : RenewalStrategy::ReauthOnly;
    }

    /**
     * LinkedIn issues refresh tokens per app, so refresh_enabled cannot vouch
     * for every credential: one stored without a refresh token (from an app
     * without programmatic refresh tokens, a manual exchange that left it
     * out, a row from before the switch) cannot be refreshed. It is renewed
     * as ReauthOnly: warned ahead of its expiry and flagged once expired,
     * instead of refused and flagged when its window opens, five days early.
     * A refresh token stored while refresh_enabled is off stays unused.
     *
     * Read from the stored ciphertext: deciding never decrypts the token.
     */
    public function renewalStrategyFor(SocialToken $token): RenewalStrategy
    {
        $strategy = $this->renewalStrategy();
        $refreshToken = $token->getAttributes()['refresh_token'] ?? null;

        return $strategy->canRenewUnattended() && ($refreshToken === null || $refreshToken === '')
            ? RenewalStrategy::ReauthOnly
            : $strategy;
    }

    /**
     * The member's own profile, or an organization's name and logo, read from
     * the member's organization list as a connection reads them. An
     * organization the member no longer administers is left to the
     * reconciliation.
     *
     * The member's name and picture come from OpenID Connect /userinfo with
     * the `profile` scope, or else from the Profile API (/v2/me) with
     * `r_basicprofile` or `r_liteprofile`, which Community Management apps
     * are granted instead: the localized first and last names, and the
     * largest size of the picture. Without the granted scopes on record, it
     * tries /userinfo, then /v2/me if /userinfo is refused.
     */
    public function fetchProfile(SocialAccount $account, Closure $accessToken): AccountProfile|RenewalResult|null
    {
        if ((string) $account->provider_user_id === (string) $account->provider_holder_id) {
            return $this->memberProfile($account, $accessToken);
        }

        $organizations = $this->organizationListOf($accessToken());

        if ($organizations instanceof RenewalResult) {
            return $organizations;
        }

        $organization = collect($organizations)->first(fn ($candidate) => (string) data_get($candidate, 'id', '') === (string) $account->provider_user_id);

        return match (true) {
            $organization === null => null,
            data_get($organization, 'name') === null => RenewalResult::unknownFailure('LinkedIn did not describe the organization (/rest/organizations).'),
            default => AccountProfile::from(data_get($organization, 'name'), null, data_get($organization, 'logo')),
        };
    }

    /**
     * @param  Closure(): string  $accessToken
     */
    protected function memberProfile(SocialAccount $account, Closure $accessToken): AccountProfile|RenewalResult|null
    {
        $openId = ! $this->grantsNoneOf($account, ['profile']);
        $basic = ! $this->grantsNoneOf($account, ['r_basicprofile', 'r_liteprofile']);

        if (! $openId && ! $basic) {
            return null;
        }

        $token = $accessToken();

        if ($openId) {
            $body = $this->profileBody($this->attempt(fn () => $this->http()->withToken($token)->acceptJson()->get(self::USERINFO_URL)), 'the LinkedIn profile');

            // An answer, a failure, or no other way to ask.
            if ($body !== null || ! $basic) {
                return is_array($body) ? AccountProfile::from($body['name'] ?? null, null, $body['picture'] ?? null) : $body;
            }
        }

        $body = $this->profileBody($this->attempt(fn () => $this->http()->withToken($token)->acceptJson()
            ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
            ->get(self::ME_URL, ['projection' => self::ME_PROJECTION])), 'the LinkedIn profile');

        if (! is_array($body)) {
            return $body;
        }

        $name = trim(implode(' ', array_filter([$body['localizedFirstName'] ?? null, $body['localizedLastName'] ?? null], 'is_string')));

        return AccountProfile::from($name, null, $this->largestPicture(data_get($body, 'profilePicture.displayImage~.elements')));
    }

    /**
     * The URL of the largest size among a picture's playableStreams, by the
     * width LinkedIn gives each (the last, which LinkedIn lists largest, when
     * it gives none).
     */
    protected function largestPicture(mixed $sizes): ?string
    {
        $best = null;
        $bestWidth = -1;

        foreach (is_array($sizes) ? $sizes : [] as $size) {
            $url = is_array($size) ? data_get($size, 'identifiers.0.identifier') : null;

            if (! is_string($url) || $url === '') {
                continue;
            }

            $image = is_array($size['data'] ?? null) ? ($size['data']['com.linkedin.digitalmedia.mediaartifact.StillImage'] ?? null) : null;
            $width = is_array($image) ? (int) data_get($image, 'storageSize.width', 0) : 0;

            if ($width >= $bestWidth) {
                [$best, $bestWidth] = [$url, $width];
            }
        }

        return $best;
    }

    /** A member's organizations: the linkedin accounts other than their profile. */
    public function reachableAccountProviders(): array
    {
        return ['linkedin'];
    }

    /**
     * The organizations the member can still post as: approved grants with a
     * posting role (fetchOrganizationGrants()), as a connection lists them,
     * without their names.
     */
    public function reachableAccountIds(SocialToken $credential): array|RenewalResult
    {
        if (blank($credential->access_token)) {
            return RenewalResult::unknownFailure('No access token to list the organizations with.');
        }

        $grants = $this->fetchOrganizationGrants((string) $credential->access_token);

        return $grants instanceof RenewalResult ? $grants : array_map('strval', array_keys($grants));
    }

    /**
     * A member reaches an organization only through a role on it, and
     * organizationAcls lists every one: missing from two reads of that list,
     * the organization is out of reach.
     */
    public function isStillReachable(SocialToken $credential, SocialAccount $account): ?bool
    {
        return false;
    }

    public function unreachableAccountReason(): string
    {
        return 'Organization no longer administered by the connected LinkedIn member.';
    }

    /**
     * fetchOrganizations(), kept a few minutes per member token.
     *
     * @return array<int, array<string, mixed>>|RenewalResult
     */
    protected function organizationListOf(#[\SensitiveParameter] string $accessToken): array|RenewalResult
    {
        $key = hash('sha256', $accessToken);
        $cached = $this->organizationLists[$key] ?? null;

        if ($cached !== null && time() - $cached['at'] < self::ORGANIZATION_LIST_SECONDS) {
            return $cached['organizations'];
        }

        $organizations = $this->fetchOrganizations($accessToken);

        // A failure too: the member's other organizations must not list
        // again, up to 20 result pages each, while LinkedIn is refusing.
        $this->organizationLists[$key] = ['at' => time(), 'organizations' => $organizations];

        return $organizations;
    }

    public function leadTime(): CarbonInterval
    {
        return CarbonInterval::days(5);
    }

    /**
     * Ask LinkedIn whether the member token still works (token introspection),
     * without renewing it. LinkedIn answers `active: false` both for a token
     * the member revoked and for a valid token issued to another LinkedIn app
     * than the configured client, so only an answer that says why is a
     * verdict: `revoked`, or `expired` when nothing can refresh the token
     * (with refresh tokens, renewal does). Anything else (a bare
     * `active: false`, a token of another client) proves nothing about the
     * member and comes back unknown, to be logged. An invalid client (401, as
     * LinkedIn documents it, or invalid_client) is the app's client failure.
     *
     * LinkedIn refuses to introspect some tokens, Community Management ones
     * among them (401 `restricted_token`, undocumented): those are checked
     * with a read the token is allowed (probeRestrictedToken()).
     */
    public function checkCredential(SocialToken $token): RenewalResult
    {
        if (empty($token->access_token)) {
            return RenewalResult::terminalFailure('Missing access token.', ['definitive' => true]);
        }

        $accessToken = $token->access_token;

        $response = $this->attempt(fn () => $this->http()->asForm()->acceptJson()->post(self::INTROSPECT_URL, [
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'token' => $accessToken,
        ]));

        if ($response instanceof RenewalResult) {
            return $response;
        }

        // LinkedIn documents 401 as "Invalid client secret" and 400 as
        // "Invalid client id or token": an invalid_client, a bare 401, or a
        // 400 naming the client is the app's client, which also renews every
        // LinkedIn token. A restricted token is not: LinkedIn will not
        // introspect it, whatever the client.
        $error = $this->introspectionError($response);

        if ($error['error'] === 'restricted_token') {
            return $this->probeRestrictedToken($token, $accessToken);
        }

        if ($error['error'] === 'invalid_client'
            || ($response->status() === 401 && $error['error'] === null)
            || ($response->status() === 400 && str_contains(strtolower((string) $error['error_description']), 'client'))) {
            return RenewalResult::clientFailure(
                "LinkedIn refused the app's client for token introspection (HTTP {$response->status()}, invalid client secret): check the client_id and client_secret the linkedin connector reads (services.".$this->credentialsKey().', or connectors.linkedin). Refreshing LinkedIn tokens uses them too.',
                $error,
            );
        }

        $active = $response->json('active');

        if ($active === true) {
            return RenewalResult::success(accessToken: $accessToken);
        }

        if ($active !== false) {
            return RenewalResult::unknownFailure('Unexpected LinkedIn token introspection response.', $error);
        }

        $status = $response->json('status');
        $status = is_string($status) ? $status : null;
        $issuedTo = $response->json('client_id');

        if (is_string($issuedTo) && $issuedTo !== '' && $issuedTo !== $this->clientId()) {
            return RenewalResult::unknownFailure(
                "LinkedIn token was issued to another LinkedIn app ({$issuedTo}): introspect it with that app's client (connectors.linkedin.client_id).",
                ['client_id' => $issuedTo, 'token_status' => $status],
            );
        }

        return match (true) {
            $status === 'revoked' => RenewalResult::terminalFailure('LinkedIn token revoked.', ['definitive' => true]),
            $status === 'expired' && ! $this->renewalStrategyFor($token)->canRenewUnattended() => RenewalResult::terminalFailure('LinkedIn token expired.', ['definitive' => true]),
            $status === 'expired' => RenewalResult::transientFailure('LinkedIn access token expired; renewal refreshes it.'),
            default => RenewalResult::unknownFailure(
                'LinkedIn token introspection answered inactive without a reason; if every LinkedIn credential does, the tokens were issued to another LinkedIn app than the configured client (services.linkedin or connectors.linkedin.client_id).',
                ['token_status' => $status],
            ),
        };
    }

    /**
     * A token LinkedIn will not introspect, checked with the first read it
     * is allowed, by the scopes it was granted (all of them, in turn, when
     * they are not on record): OpenID /userinfo (`profile`), the Profile API
     * /v2/me (`r_basicprofile`, `r_liteprofile`), then one organization grant
     * (`r_organization_admin`, `rw_organization_admin`). A read refused for
     * want of a scope (403) moves on to the next.
     *
     * An answer is the token working. A 401 naming a revoked or invalid
     * token (REVOKED_ACCESS_TOKEN / 65601, INVALID_ACCESS_TOKEN / 65600) is a
     * verdict, never a definitive one: LinkedIn does not document these
     * codes, so it takes two runs in a row to flag the credential. An expired
     * one is left to the renewal, as introspection's `expired` is. Anything
     * else decides nothing.
     */
    protected function probeRestrictedToken(SocialToken $token, #[\SensitiveParameter] string $accessToken): RenewalResult
    {
        $granted = is_array($token->scopes) && $token->scopes !== [] ? $token->scopes : null;
        $probes = [
            [['profile'], fn () => $this->http()->withToken($accessToken)->acceptJson()->get(self::USERINFO_URL)],
            [['r_basicprofile', 'r_liteprofile'], fn () => $this->http()->withToken($accessToken)->acceptJson()->get(self::ME_URL)],
            [['r_organization_admin', 'rw_organization_admin'], fn () => $this->rest($accessToken)->get(self::ORGANIZATION_ACLS_URL, ['q' => 'roleAssignee', 'start' => 0, 'count' => 1])],
        ];

        foreach ($probes as [$scopes, $read]) {
            if ($granted !== null && array_intersect($scopes, $granted) === []) {
                continue;
            }

            $response = $this->attempt($read);

            if ($response instanceof RenewalResult) {
                return $response;
            }

            if ($response->successful()) {
                return RenewalResult::success(accessToken: $accessToken);
            }

            if ($response->status() === 403) {
                continue;
            }

            if ($response->status() === 426) {
                return $this->restFailure($response, 'Could not check the LinkedIn token');
            }

            return $this->probeVerdict($token, $response);
        }

        return RenewalResult::unknownFailure('LinkedIn does not introspect this token (restricted_token), and it grants no read to check it with (profile, r_basicprofile, r_liteprofile, r_organization_admin or rw_organization_admin).');
    }

    /** What a LinkedIn API read refused with a 401 or another error says of the token. */
    protected function probeVerdict(SocialToken $token, Response $response): RenewalResult
    {
        $code = $response->json('code');
        $code = is_string($code) ? $code : '';
        $service = $response->json('serviceErrorCode');
        $service = is_numeric($service) ? (int) $service : 0;
        $message = $response->json('message');
        $message = is_string($message) ? strtolower($message) : '';
        $context = ['status' => $response->status(), 'code' => $code !== '' ? $code : null, 'service_error_code' => $service ?: null];

        if ($response->status() !== 401) {
            return RenewalResult::unknownFailure("Could not check the LinkedIn token LinkedIn does not introspect (HTTP {$response->status()}).", $context);
        }

        return match (true) {
            $code === 'REVOKED_ACCESS_TOKEN' || $service === 65601 || str_contains($message, 'revoked') => RenewalResult::terminalFailure('LinkedIn token revoked (answered by the API: LinkedIn does not introspect it).', $context),
            $code === 'EXPIRED_ACCESS_TOKEN' || $service === 65602 || str_contains($message, 'expired') => $this->renewalStrategyFor($token)->canRenewUnattended()
                ? RenewalResult::transientFailure('LinkedIn access token expired; renewal refreshes it.')
                : RenewalResult::terminalFailure('LinkedIn token expired.', $context),
            $code === 'INVALID_ACCESS_TOKEN' || $service === 65600 => RenewalResult::terminalFailure('LinkedIn token invalid (answered by the API: LinkedIn does not introspect it).', $context),
            default => RenewalResult::unknownFailure('Unexpected LinkedIn answer checking a token LinkedIn does not introspect.', $context),
        };
    }

    /**
     * What LinkedIn said, for the log: the status, and its error and
     * description if it gave any (they hold no secret), shortened.
     *
     * @return array{status: int, error: ?string, error_description: ?string}
     */
    protected function introspectionError(Response $response): array
    {
        $text = function (mixed $value): ?string {
            return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 200) : null;
        };

        return [
            'status' => $response->status(),
            'error' => $text($response->json('error') ?? $response->json('code')),
            'error_description' => $text($response->json('error_description') ?? $response->json('message')),
        ];
    }

    protected function tokenUrl(): string
    {
        return self::TOKEN_URL;
    }

    protected function missingRefreshTokenReason(): string
    {
        return 'No refresh token (re-authorisation required).';
    }

    protected function refreshExpiresInKey(): ?string
    {
        return 'refresh_token_expires_in';
    }

    /**
     * refresh_token_client_mismatch: the refresh token was issued to another
     * LinkedIn app (e.g. after switching apps): the member must re-authorise.
     */
    protected function classifyError(string $error, string $description, int $status): ?string
    {
        return $error === 'refresh_token_client_mismatch'
            ? self::ERROR_TERMINAL
            : parent::classifyError($error, $description, $status);
    }

    /**
     * List the organizations (company Pages) a member administers, with their
     * names and logos. Each posts with this same member token
     * (w_organization_social), so an organization row mirrors the member's
     * credential rather than holding its own. Requires the member token to
     * carry an organization admin scope (e.g. rw_organization_admin). Which
     * organizations, and the role each is held through: fetchOrganizationGrants().
     *
     * Names and logos come after the grants, in batches: from
     * /rest/organizations, then the logos' URLs from /rest/images. Those URLs
     * are signed and expire within weeks: the daily profile sync renews them.
     * Names and logos are best effort, and never fail the list. An
     * organization LinkedIn does not describe comes back with a null `name`
     * (a connection then keeps the name and logo it stored); one whose logo
     * URL it does not give, without a `logo` key (the stored logo is kept).
     * `logo` is null only for an organization without a logo.
     *
     * @return array<int, array<string, mixed>>|RenewalResult
     */
    public function fetchOrganizations(#[\SensitiveParameter] string $accessToken): array|RenewalResult
    {
        $grants = $this->fetchOrganizationGrants($accessToken);

        if ($grants instanceof RenewalResult || $grants === []) {
            return $grants;
        }

        $details = $this->organizationDetails($accessToken, array_map('strval', array_keys($grants)));
        $logoUrls = $this->imageUrls($accessToken, array_values(array_filter(array_column($details, 'logo'))));
        $organizations = [];

        foreach ($grants as $id => $role) {
            $id = (string) $id;
            $detail = $details[$id] ?? null;
            $organization = ['id' => $id, 'urn' => "urn:li:organization:{$id}", 'name' => $detail['name'] ?? null];

            if ($detail !== null && $detail['logo'] === null) {
                $organization['logo'] = null;
            } elseif ($detail !== null && isset($logoUrls[$detail['logo']])) {
                $organization['logo'] = $logoUrls[$detail['logo']];
            }

            $organizations[] = $organization + ['role' => $role];
        }

        return $organizations;
    }

    /**
     * The organizations the member can post as, keyed by id (an int, as PHP
     * keys digits), with the role each is held through: /rest/organizationAcls, approved grants with a posting
     * role (config `posting_roles`), the first posting role seen for each.
     * The reconciliation needs no more: one listing, no names.
     *
     * Pages through the results with start/count: the reconciliation and a
     * connection both go by this list, so a truncated one would flag
     * organizations the member still administers. It goes on while LinkedIn
     * announces paging.links rel=next or serves a full page (per
     * paging.count). LinkedIn orders the grants differently from one call to
     * the next, so a page can repeat a grant an earlier page served, and skip
     * another in its place. Any failure returns a RenewalResult, never a
     * partial list: a non-2xx, a 2xx without an `elements` list, a grant
     * served twice, an approved posting grant without an organization URN, or
     * the safety cap.
     *
     * @return array<int, string>|RenewalResult
     */
    public function fetchOrganizationGrants(#[\SensitiveParameter] string $accessToken): array|RenewalResult
    {
        $elements = [];
        $served = [];
        $start = 0;

        for ($resultPage = 0; $resultPage < self::MAX_RESULT_PAGES; $resultPage++) {
            $response = $this->attempt(fn () => $this->rest($accessToken)->get(self::ORGANIZATION_ACLS_URL, [
                'q' => 'roleAssignee',
                'start' => $start,
                'count' => self::ORGANIZATION_PAGE_SIZE,
            ]));

            if ($response instanceof RenewalResult) {
                return $response;
            }

            if ($response->failed()) {
                return $this->restFailure($response, 'Could not list LinkedIn organizations', ['start' => $start]);
            }

            $batch = $response->json('elements');

            if (! is_array($batch) || ! array_is_list($batch)) {
                return RenewalResult::unknownFailure('Could not list LinkedIn organizations (HTTP '.$response->status().', no elements list).', [
                    'status' => $response->status(),
                    'start' => $start,
                ]);
            }

            $page = [];

            foreach ($batch as $element) {
                $key = is_array($element) && is_string($element['organization'] ?? null)
                    ? json_encode([$element['organization'], $element['role'] ?? null])
                    : false;

                if ($key !== false && isset($served[$key])) {
                    return RenewalResult::unknownFailure('LinkedIn served an organization grant twice while paging; refusing a list that may miss another.', [
                        'start' => $start,
                    ]);
                }

                if ($key !== false) {
                    $page[$key] = true;
                }
            }

            $served += $page;
            $elements = array_merge($elements, $batch);
            $start += count($batch);
            $total = $response->json('paging.total');

            // The page size LinkedIn actually served, and whether it says more follow.
            $pageSize = min(self::ORGANIZATION_PAGE_SIZE, (int) ($response->json('paging.count') ?: self::ORGANIZATION_PAGE_SIZE));
            $links = $response->json('paging.links');
            $hasNext = is_array($links) && in_array('next', array_column(array_filter($links, 'is_array'), 'rel'), true);

            $done = $batch === []
                || ($total !== null ? $start >= (int) $total : (! $hasNext && count($batch) < $pageSize));

            if ($done) {
                return $this->postableGrants($elements);
            }
        }

        return RenewalResult::unknownFailure('Too many LinkedIn organizations to list; refusing to return a partial list.', [
            'result_pages' => self::MAX_RESULT_PAGES,
        ]);
    }

    /**
     * The organizationAcls roles kept as postable (config `posting_roles`).
     * An empty or missing list falls back to the defaults: filtering out every
     * organization would reconcile them all away.
     *
     * @return array<int, string>
     */
    protected function postingRoles(): array
    {
        $roles = $this->config['posting_roles'] ?? null;

        if (is_string($roles)) {
            $roles = array_filter(array_map('trim', explode(',', $roles)));
        }

        return is_array($roles) && $roles !== [] ? array_values($roles) : self::DEFAULT_POSTING_ROLES;
    }

    /**
     * Name and logo of each organization LinkedIn describes, by id, read in
     * batches from /rest/organizations: `logo` is its image URN, null for an
     * organization without a logo. One it does not describe (a batch that
     * failed, an error for that id) is left out.
     *
     * @param  array<int, string>  $ids
     * @return array<array-key, array{name: ?string, logo: ?string}>
     */
    protected function organizationDetails(#[\SensitiveParameter] string $accessToken, array $ids): array
    {
        $details = [];

        foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk) {
            // Rest.li 2.0 batch syntax: List( , ) left unencoded.
            $response = $this->attempt(fn () => $this->rest($accessToken)->get(self::ORGANIZATIONS_URL.'?ids=List('.implode(',', $chunk).')'));
            $results = $response instanceof RenewalResult || $response->failed() ? null : $response->json('results');

            foreach (is_array($results) ? $results : [] as $id => $organization) {
                if (! is_array($organization)) {
                    continue;
                }

                $name = $organization['localizedName'] ?? null;
                $logo = data_get($organization, 'logoV2.original');

                $details[(string) $id] = [
                    'name' => is_string($name) && trim($name) !== '' ? $name : null,
                    'logo' => is_string($logo) && $logo !== '' ? $logo : null,
                ];
            }
        }

        return $details;
    }

    /**
     * The download URL of each logo, keyed by the URN /rest/organizations
     * gave, read in batches from /rest/images. Those take urn:li:image URNs:
     * a logo's urn:li:digitalmediaAsset URN names the same asset. A URL
     * LinkedIn does not give is left out.
     *
     * @param  array<int, string>  $urns
     * @return array<string, string>
     */
    protected function imageUrls(#[\SensitiveParameter] string $accessToken, array $urns): array
    {
        $images = [];

        foreach (array_unique($urns) as $urn) {
            if (preg_match('/^urn:li:(?:digitalmediaAsset|image):([A-Za-z0-9_-]+)$/', $urn, $matches) === 1) {
                $images["urn:li:image:{$matches[1]}"] = $urn;
            }
        }

        $urls = [];

        foreach (array_chunk($images, self::BATCH_SIZE, true) as $chunk) {
            $list = implode(',', array_map('rawurlencode', array_keys($chunk)));
            $response = $this->attempt(fn () => $this->rest($accessToken)->get(self::IMAGES_URL."?ids=List({$list})"));
            $results = $response instanceof RenewalResult || $response->failed() ? null : $response->json('results');

            foreach ($chunk as $imageUrn => $urn) {
                $url = is_array($results) ? ($results[$imageUrn]['downloadUrl'] ?? null) : null;

                if (is_string($url) && $url !== '') {
                    $urls[$urn] = $url;
                }
            }
        }

        return $urls;
    }

    /**
     * @param  array<int, mixed>  $elements  Raw organizationAcls elements.
     * @return array<int, string>|RenewalResult
     */
    private function postableGrants(array $elements): array|RenewalResult
    {
        $grants = [];
        $postingRoles = $this->postingRoles();

        foreach ($elements as $element) {
            // Only approved grants with a posting role are postable. Filter
            // before resolving ids, so a grant we would drop anyway (ANALYST,
            // CURATOR...) never blocks the listing.
            if (! is_array($element) || ($element['state'] ?? null) !== 'APPROVED') {
                continue;
            }

            if (! in_array($element['role'] ?? null, $postingRoles, true)) {
                continue;
            }

            $urn = $element['organization'] ?? $element['organizationTarget'] ?? null;

            // An approved grant we cannot place would read as "no longer
            // administered": refuse the whole list rather than reconcile on it.
            if (! is_string($urn) || preg_match('/^urn:li:organization:(\d+)$/', $urn, $matches) !== 1) {
                return RenewalResult::unknownFailure('LinkedIn returned an approved organization grant without a resolvable organization.');
            }

            // LinkedIn returns one element per (organization, role): keep the
            // first posting role seen for each organization.
            $grants[(int) $matches[1]] ??= (string) $element['role'];
        }

        return $grants;
    }

    /** A request to LinkedIn's versioned API, as the member. */
    protected function rest(#[\SensitiveParameter] string $accessToken): PendingRequest
    {
        $this->warnAboutAgingVersion();

        return $this->http()->withToken($accessToken)->acceptJson()->withHeaders([
            'LinkedIn-Version' => $this->apiVersion(),
            'X-Restli-Protocol-Version' => '2.0.0',
        ]);
    }

    /**
     * A failed /rest call. A 426 means LinkedIn no longer serves the
     * configured version: every LinkedIn listing fails until it is moved to a
     * current one, so it is logged as critical, once a day.
     *
     * @param  array<string, mixed>  $context
     */
    protected function restFailure(Response $response, string $what, array $context = []): RenewalResult
    {
        if ($response->status() !== 426) {
            return RenewalResult::unknownFailure("{$what} (HTTP {$response->status()}).", ['status' => $response->status()] + $context);
        }

        $version = $this->apiVersion();
        $reason = "LinkedIn no longer serves API version {$version} (HTTP 426): set LINKEDIN_API_VERSION (connectors.linkedin.api_version) to a current version.";

        if ($this->firstTimeToday("refused:{$version}")) {
            Log::critical("[social-tokens] {$reason}", ['provider' => 'linkedin', 'api_version' => $version]);
        }

        return RenewalResult::unknownFailure($reason, ['status' => 426, 'api_version' => $version] + $context);
    }

    /**
     * Once a day: the configured LinkedIn-Version nears the end of the year
     * LinkedIn guarantees each version.
     */
    protected function warnAboutAgingVersion(): void
    {
        $version = $this->apiVersion();
        $released = Carbon::createMidnightDate((int) substr($version, 0, 4), (int) substr($version, 4, 2), 1);

        if ($released->addMonths(self::API_VERSION_WARNING_MONTHS)->isFuture() || ! $this->firstTimeToday("aging:{$version}")) {
            return;
        }

        Log::warning("[social-tokens] LinkedIn API version {$version} is over ".self::API_VERSION_WARNING_MONTHS.' months old, and LinkedIn supports a version for as little as a year: set LINKEDIN_API_VERSION (connectors.linkedin.api_version) to a recent version.', [
            'provider' => 'linkedin',
            'api_version' => $version,
        ]);
    }

    private function firstTimeToday(string $what): bool
    {
        $key = $what.'@'.Carbon::now()->toDateString();

        if (isset(self::$notices[$key])) {
            return false;
        }

        return self::$notices[$key] = true;
    }
}
