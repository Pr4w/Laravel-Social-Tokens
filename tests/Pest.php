<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Two\User as SocialiteUser;
use Pr4w\SocialTokens\Tests\Fixtures\SocialiteProvidersUser;
use Pr4w\SocialTokens\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * Build a Socialite user with sensible defaults, overridable per test. Given an
 * accessTokenResponseBody, the user of a SocialiteProviders driver; otherwise
 * core Socialite's, which has none.
 */
function socialiteUser(array $attrs = []): SocialiteUser
{
    $user = array_key_exists('accessTokenResponseBody', $attrs) ? new SocialiteProvidersUser : new SocialiteUser;

    // Socialite users are ArrayAccess over this raw array; data_get() touches it.
    $user->setRaw($attrs['raw'] ?? []);

    $user->map([
        'id' => $attrs['id'] ?? 'ext-1',
        'name' => $attrs['name'] ?? 'Test User',
        'nickname' => $attrs['nickname'] ?? 'tester',
        'email' => $attrs['email'] ?? 'test@example.com',
        'avatar' => $attrs['avatar'] ?? 'https://example.com/a.png',
    ]);

    $user->token = $attrs['token'] ?? 'access-token';
    $user->refreshToken = $attrs['refreshToken'] ?? null;
    $user->expiresIn = $attrs['expiresIn'] ?? 3600;
    $user->approvedScopes = $attrs['approvedScopes'] ?? [];

    if ($user instanceof SocialiteProvidersUser) {
        $user->accessTokenResponseBody = $attrs['accessTokenResponseBody'];
    }

    return $user;
}

/**
 * Fakes of LinkedIn's versioned organization listing, built from
 * organizationAcls elements decorated the /v2 way (`organization~` with an id,
 * a localizedName and a logoV2 `original~` playableStreams URL), so a test
 * keeps its data in one place: /rest/organizationAcls answers the elements
 * undecorated (the `organization` URN), /rest/organizations serves the names
 * and logos the decorations held, and /rest/images the logos' URLs. An
 * organization no decoration described, or whose element carries
 * `organization!` (the /v2 throttled decoration), is not described: a 404.
 *
 * $acl is what Http::fake() takes for one URL: a response, an array, a
 * sequence, or a closure (returning null lets a later fake answer).
 *
 * @return array<string, Closure>
 */
function linkedInRest(mixed $acl): array
{
    $described = new ArrayObject;

    return [
        'api.linkedin.com/rest/organizationAcls*' => function (Request $request, array $options = []) use ($acl, $described) {
            $response = match (true) {
                $acl instanceof Closure, $acl instanceof ResponseSequence => $acl($request, $options),
                is_array($acl) => Http::response($acl),
                default => $acl,
            };

            if (! $response instanceof PromiseInterface) {
                return $response;
            }

            $psr = $response->wait();
            $raw = (string) $psr->getBody();
            $body = json_decode($raw, true);

            if (! is_array($body) || ! is_array($body['elements'] ?? null)) {
                return Http::response($raw, $psr->getStatusCode(), $psr->getHeaders());
            }

            $body['elements'] = array_map(function (mixed $element) use ($described) {
                if (! is_array($element)) {
                    return $element;
                }

                $organization = $element['organization~'] ?? null;

                // `organization!`: LinkedIn does not describe the organization this time.
                if (isset($element['organization!']) && preg_match('/(\d+)$/', (string) ($element['organization'] ?? ''), $matches) === 1) {
                    unset($described[$matches[1]]);
                }

                unset($element['organization~'], $element['organization!']);

                if (is_array($organization) && isset($organization['id'])) {
                    $element['organization'] ??= 'urn:li:organization:'.$organization['id'];
                    $described[(string) $organization['id']] = $organization;
                }

                return $element;
            }, $body['elements']);

            return Http::response($body, $psr->getStatusCode(), $psr->getHeaders());
        },
        'api.linkedin.com/rest/organizations*' => function (Request $request) use ($described) {
            preg_match('/ids=List\(([^)]*)\)/', urldecode($request->url()), $matches);
            $answer = ['results' => [], 'statuses' => [], 'errors' => []];

            foreach (array_filter(explode(',', $matches[1] ?? '')) as $id) {
                $organization = $described[$id] ?? null;

                if ($organization === null) {
                    $answer['statuses'][$id] = 404;
                    $answer['errors'][$id] = ['status' => 404, 'message' => 'Not found'];

                    continue;
                }

                $answer['statuses'][$id] = 200;
                $answer['results'][$id] = array_filter([
                    'id' => (int) $id,
                    'localizedName' => $organization['localizedName'] ?? null,
                    'logoV2' => linkedInDecoratedLogo($organization) === null ? null : ['original' => "urn:li:digitalmediaAsset:logo-{$id}"],
                ], fn (mixed $value) => $value !== null);
            }

            return Http::response($answer);
        },
        'api.linkedin.com/rest/images*' => function (Request $request) use ($described) {
            preg_match('/ids=List\(([^)]*)\)/', $request->url(), $matches);
            $answer = ['results' => [], 'statuses' => [], 'errors' => []];

            foreach (array_filter(explode(',', $matches[1] ?? '')) as $encoded) {
                $urn = rawurldecode($encoded);
                $url = linkedInDecoratedLogo($described[(string) preg_replace('/^urn:li:image:logo-/', '', $urn)] ?? []);

                if ($url !== null) {
                    $answer['results'][$urn] = ['id' => $urn, 'status' => 'AVAILABLE', 'downloadUrl' => $url, 'downloadUrlExpiresAt' => (time() + 14 * 86400) * 1000];
                }
            }

            return Http::response($answer);
        },
    ];
}

/** The logo URL a /v2 `organization~` decoration held. */
function linkedInDecoratedLogo(array $organization): ?string
{
    $url = data_get($organization, 'logoV2.original~.elements.0.identifiers.0.identifier');

    return is_string($url) ? $url : null;
}
