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
 *    with an admin role on that page (ADMINISTRATOR / DIRECT_SPONSORED_CONTENT_
 *    POSTER / RECRUITING_POSTER)
 *  - the default below targets company pages via the Community Management API;
 *    override 'scopes' in config to change it
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
                'body' => $body,
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
     * an organization admin scope (e.g. rw_organization_admin).
     *
     * Pages through the results with start/count: the action reconciles against
     * this list, so a truncated one would flag organizations the member still
     * administers. Any failure returns a RenewalResult, never a partial list.
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
                    'projection' => '(paging,elements*(role,state,organization~(id,localizedName,logoV2(original~:playableStreams))))',
                    'start' => $start,
                    'count' => self::ORGANIZATION_PAGE_SIZE,
                ]));

            if ($response instanceof RenewalResult) {
                return $response;
            }

            if ($response->failed()) {
                return RenewalResult::unknownFailure('Could not list LinkedIn organizations (HTTP '.$response->status().').', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
            }

            $batch = $response->json('elements', []);
            $elements = array_merge($elements, $batch);
            $start += count($batch);
            $total = $response->json('paging.total');

            $done = $batch === []
                || ($total !== null ? $start >= (int) $total : count($batch) < self::ORGANIZATION_PAGE_SIZE);

            if ($done) {
                return $this->mapOrganizations($elements);
            }
        }

        return RenewalResult::unknownFailure('Too many LinkedIn organizations to list; refusing to return a partial list.', [
            'result_pages' => self::MAX_RESULT_PAGES,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $elements  Raw organizationAcls elements.
     * @return array<int, array<string, mixed>>
     */
    private function mapOrganizations(array $elements): array
    {
        $organizations = [];

        foreach ($elements as $element) {
            // Only approved admin grants are postable.
            if (($element['state'] ?? null) !== 'APPROVED') {
                continue;
            }

            $org = $element['organization~'] ?? [];
            $id = $org['id'] ?? null;

            if ($id === null) {
                continue;
            }

            $organizations[] = [
                'id' => (string) $id,
                'urn' => "urn:li:organization:{$id}",
                'name' => $org['localizedName'] ?? null,
                'logo' => data_get($org, 'logoV2.original~.elements.0.identifiers.0.identifier'),
                'role' => $element['role'] ?? null,
            ];
        }

        return $organizations;
    }
}
