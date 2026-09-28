<?php

namespace Pr4w\SocialTokens\Connectors;

use Carbon\CarbonInterval;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\RenewalResult;

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
 */
class LinkedInConnector extends AbstractConnector
{
    protected const TOKEN_URL = 'https://www.linkedin.com/oauth/v2/accessToken';

    protected const ORGANIZATION_ACLS_URL = 'https://api.linkedin.com/v2/organizationAcls';

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

    public function renewalStrategy(): RenewalStrategy
    {
        return ($this->config['refresh_enabled'] ?? false)
            ? RenewalStrategy::StableRefreshToken
            : RenewalStrategy::ReauthOnly;
    }

    public function leadTime(): CarbonInterval
    {
        return CarbonInterval::days(5);
    }

    public function refreshCredential(SocialToken $token): RenewalResult
    {
        return $this->refreshWithToken($token->refresh_token);
    }

    private function refreshWithToken(?string $refreshToken): RenewalResult
    {
        if (empty($refreshToken)) {
            return RenewalResult::terminalFailure('No refresh token (re-authorisation required).');
        }

        $response = $this->attempt(fn () => $this->http()->asForm()->acceptJson()->post(self::TOKEN_URL, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
        ]));

        if ($response instanceof RenewalResult) {
            return $response;
        }

        $body = $response->json() ?? [];

        if (! empty($body['error'])) {
            $error = (string) $body['error'];
            $description = (string) ($body['error_description'] ?? '');

            // Terminal: the member's grant is gone (refresh token expired or
            // revoked, the one year cap reached, or issued to another LinkedIn
            // app after switching apps). The member must re-authorise.
            if (in_array($error, ['invalid_grant', 'refresh_token_client_mismatch'], true)
                || ($error === 'invalid_request' && $this->isAboutRefreshToken($description))) {
                return RenewalResult::terminalFailure(trim("{$error}: {$description}"));
            }

            // The app's own client is misconfigured: not the member's problem.
            if (in_array($error, ['invalid_client', 'unauthorized_client', 'invalid_request'], true)) {
                return RenewalResult::clientFailure(trim("{$error}: {$description}"), [
                    'error' => $error,
                    'error_description' => $description,
                ]);
            }

            return RenewalResult::unknownFailure(trim("{$error}: {$description}"), [
                'error' => $error,
                'error_description' => $description,
            ]);
        }

        $accessToken = $body['access_token'] ?? null;

        if ($accessToken === null) {
            return RenewalResult::unknownFailure('Malformed response, no access_token.', [
                'status' => $response->status(),
                'keys' => array_keys($body), // never the body: it may carry tokens
            ]);
        }

        return RenewalResult::success(
            accessToken: $accessToken,
            expiresAt: isset($body['expires_in']) ? now()->addSeconds((int) $body['expires_in']) : null,
            refreshToken: $body['refresh_token'] ?? null,
            refreshExpiresAt: isset($body['refresh_token_expires_in'])
                ? now()->addSeconds((int) $body['refresh_token_expires_in'])
                : null,
            profile: array_filter([
                'scope' => $body['scope'] ?? null,
            ]),
        );
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
    public function fetchOrganizations(string $accessToken): array|RenewalResult
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
