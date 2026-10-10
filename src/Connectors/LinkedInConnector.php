<?php

namespace Pr4w\SocialTokens\Connectors;

use Carbon\CarbonInterval;
use Pr4w\SocialTokens\Contracts\ChecksCredential;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialToken;
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
 *  - default: ReauthOnly, CredentialExpiringSoon fires ahead of the 60 day
 *    expiry; the credential keeps posting until then, and is flagged
 *    needs_reconnect only once it has actually expired
 *  - with 'refresh_enabled' => true (you have refresh tokens): StableRefreshToken
 *    for every credential that holds a refresh token, ReauthOnly for the others
 */
class LinkedInConnector extends OAuth2Connector implements ChecksCredential
{
    protected const TOKEN_URL = 'https://www.linkedin.com/oauth/v2/accessToken';

    protected const ORGANIZATION_ACLS_URL = 'https://api.linkedin.com/v2/organizationAcls';

    protected const INTROSPECT_URL = 'https://www.linkedin.com/oauth/v2/introspectToken';

    protected const ORGANIZATION_PAGE_SIZE = 100;

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
     * `refresh_enabled` is read as every boolean setting (Settings::flag()):
     * LINKEDIN_REFRESH_ENABLED=off reaches the config as the string 'off'.
     */
    public function renewalStrategy(): RenewalStrategy
    {
        return Settings::flag($this->config['refresh_enabled'] ?? false, false)
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
     * `active: false`, a token of another client, invalid_client) proves
     * nothing about the member and comes back unknown, to be logged.
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

        $active = $response->json('active');

        if ($active === true) {
            return RenewalResult::success(accessToken: $accessToken);
        }

        if ($active !== false) {
            return RenewalResult::unknownFailure('Unexpected LinkedIn token introspection response.', ['status' => $response->status()]);
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
     * List the organizations (company Pages) a member administers, via
     * organizationAcls. Each posts with this same member token
     * (w_organization_social), so an organization row mirrors the member's
     * credential rather than holding its own. Requires the member token to carry
     * an organization admin scope (e.g. rw_organization_admin). Only approved
     * grants with a posting role (config `posting_roles`) are kept, one entry
     * per organization.
     *
     * Pages through the results with start/count: the action reconciles against
     * this list, so a truncated one would flag organizations the member still
     * administers. It goes on while LinkedIn announces paging.links rel=next or
     * serves a full page (per paging.count). An organization whose decoration
     * LinkedIn throttled is resolved from its URN. Any failure returns a
     * RenewalResult, never a partial list: a non-2xx, a 2xx without an
     * `elements` list, an approved grant it cannot resolve, or the safety cap.
     *
     * @return array<int, array<string, mixed>>|RenewalResult
     */
    public function fetchOrganizations(#[\SensitiveParameter] string $accessToken): array|RenewalResult
    {
        $elements = [];
        $start = 0;

        for ($resultPage = 0; $resultPage < self::MAX_RESULT_PAGES; $resultPage++) {
            $response = $this->attempt(fn () => $this->http()->withToken($accessToken)
                ->acceptJson()
                ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
                ->get(self::ORGANIZATION_ACLS_URL, [
                    'q' => 'roleAssignee',
                    'projection' => '(paging,elements*(role,state,organization,organization~(id,localizedName,logoV2(original~:playableStreams))))',
                    'start' => $start,
                    'count' => self::ORGANIZATION_PAGE_SIZE,
                ]));

            if ($response instanceof RenewalResult) {
                return $response;
            }

            if ($response->failed()) {
                return RenewalResult::unknownFailure('Could not list LinkedIn organizations (HTTP '.$response->status().').', [
                    'status' => $response->status(),
                    'start' => $start,
                ]);
            }

            $batch = $response->json('elements');

            if (! is_array($batch) || ! array_is_list($batch)) {
                return RenewalResult::unknownFailure('Could not list LinkedIn organizations (HTTP '.$response->status().', no elements list).', [
                    'status' => $response->status(),
                    'start' => $start,
                ]);
            }

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
                return $this->mapOrganizations($elements);
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
     * @param  array<int, array<string, mixed>>  $elements  Raw organizationAcls elements.
     * @return array<int, array<string, mixed>>|RenewalResult
     */
    private function mapOrganizations(array $elements): array|RenewalResult
    {
        $organizations = [];
        $postingRoles = $this->postingRoles();

        foreach ($elements as $element) {
            // Only approved grants with a posting role are postable. Filter
            // before resolving ids, so a grant we would drop anyway (ANALYST,
            // CURATOR...) never blocks the listing.
            if (($element['state'] ?? null) !== 'APPROVED') {
                continue;
            }

            if (! in_array($element['role'] ?? null, $postingRoles, true)) {
                continue;
            }

            // LinkedIn swaps `organization~` for `organization!` when it throttles
            // decoration: name and logo are then unknown, the id is in the URN.
            $org = is_array($element['organization~'] ?? null) ? $element['organization~'] : [];
            $id = $org['id'] ?? null;

            if ($id === null && preg_match('/^urn:li:organization:(\d+)$/', (string) ($element['organization'] ?? ''), $matches)) {
                $id = $matches[1];
            }

            // An approved grant we cannot place would read as "no longer
            // administered": refuse the whole list rather than reconcile on it.
            if ($id === null) {
                return RenewalResult::unknownFailure('LinkedIn returned an approved organization grant without a resolvable organization.');
            }

            // LinkedIn returns one element per (organization, role): keep the
            // first posting role seen for each organization.
            if (isset($organizations[(string) $id])) {
                continue;
            }

            $organizations[(string) $id] = [
                'id' => (string) $id,
                'urn' => "urn:li:organization:{$id}",
                'name' => $org['localizedName'] ?? null,
                'logo' => data_get($org, 'logoV2.original~.elements.0.identifiers.0.identifier'),
                'role' => $element['role'],
            ];
        }

        return array_values($organizations);
    }
}
