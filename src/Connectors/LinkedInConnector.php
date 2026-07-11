<?php

namespace Pr4w\SocialTokens\Connectors;

use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialAccount;
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
 *  - default: ReauthOnly, the account is flagged for reconnection ahead of the
 *    60 day expiry
 *  - with 'refresh_enabled' => true (you have refresh tokens): StableRefreshToken
 */
class LinkedInConnector extends AbstractConnector
{
    protected const TOKEN_URL = 'https://www.linkedin.com/oauth/v2/accessToken';

    protected const ORGANIZATION_ACLS_URL = 'https://api.linkedin.com/v2/organizationAcls';

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

    public function renew(SocialAccount $account): RenewalResult
    {
        if (empty($account->refresh_token)) {
            return RenewalResult::terminalFailure('No refresh token (re-authorisation required).');
        }

        $response = $this->attempt(fn () => Http::asForm()->acceptJson()->post(self::TOKEN_URL, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $account->refresh_token,
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

            // Known terminal cases: refresh token expired/revoked, the one year
            // cap reached, or bad client. The member must re-authorise.
            $terminal = ['invalid_grant', 'invalid_client', 'unauthorized_client', 'invalid_request'];

            if (in_array($error, $terminal, true)) {
                return RenewalResult::terminalFailure(trim("{$error}: {$description}"));
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
        );
    }

    /**
     * List the organizations (company Pages) a member administers, via
     * organizationAcls. Each posts with this same member token
     * (w_organization_social), so an organization row mirrors the member's
     * credential rather than holding its own. Requires the member token to carry
     * an organization admin scope (e.g. rw_organization_admin).
     *
     * @return array<int, array<string, mixed>>|RenewalResult
     */
    public function fetchOrganizations(string $accessToken): array|RenewalResult
    {
        $response = $this->attempt(fn () => Http::withToken($accessToken)
            ->acceptJson()
            ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
            ->get(self::ORGANIZATION_ACLS_URL, [
                'q' => 'roleAssignee',
                'projection' => '(paging,elements*(role,state,organization~(id,localizedName,logoV2(original~:playableStreams))))',
                'count' => 100,
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

        $organizations = [];

        foreach ($response->json('elements', []) as $element) {
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
