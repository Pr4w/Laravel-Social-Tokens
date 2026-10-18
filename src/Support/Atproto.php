<?php

namespace Pr4w\SocialTokens\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * AT Protocol (Bluesky) identity and OAuth discovery: a handle resolves to a
 * DID, the DID document names the PDS hosting the account, the PDS names its
 * authorization server, whose metadata gives the OAuth endpoints. Accounts
 * can live on any PDS, so none of this is a constant.
 *
 * Whoever controls a handle controls where that chain leads (a did:web
 * domain, the PDS a DID document names, the endpoints a server's metadata
 * lists), and the app's server fetches each step. So every URL it reaches is
 * checked first (publicUrl()): https only, and never a private, loopback,
 * link-local or reserved address, which would let a handle point the app at
 * its own network.
 *
 * @see https://atproto.com/specs/oauth
 */
final class Atproto
{
    /** @var (callable(string): array<int, string>)|null resolves a host name to its addresses (tests replace it) */
    private static $resolver = null;

    /** Where handles are resolved and accounts without a handle sign in. */
    public const DEFAULT_ENTRYWAY = 'https://bsky.social';

    public const PLC_DIRECTORY = 'https://plc.directory';

    /** The public AppView: profiles and posts, no authentication. */
    public const PUBLIC_APPVIEW = 'https://public.api.bsky.app';

    /** A handle (alice.bsky.social, @alice.example.com) → its DID. */
    public static function resolveHandle(string $handle): string
    {
        $handle = strtolower(ltrim(trim($handle), '@'));

        if (str_starts_with($handle, 'did:')) {
            return $handle;
        }

        $did = self::http()->get(self::entryway().'/xrpc/com.atproto.identity.resolveHandle', ['handle' => $handle])->throw()->json('did');

        if (! is_string($did) || ! str_starts_with($did, 'did:')) {
            throw new RuntimeException("The Bluesky handle [{$handle}] does not resolve.");
        }

        return $did;
    }

    /**
     * The DID document of did:plc (plc.directory) and did:web (the domain's
     * .well-known) identities.
     *
     * @return array<string, mixed>
     */
    public static function didDocument(string $did): array
    {
        $url = match (true) {
            str_starts_with($did, 'did:plc:') => self::PLC_DIRECTORY.'/'.$did,
            str_starts_with($did, 'did:web:') => self::publicUrl('https://'.rawurldecode(substr($did, 8)).'/.well-known/did.json', "The DID document of [{$did}]"),
            default => throw new RuntimeException("Unsupported DID method [{$did}]."),
        };

        $document = self::http()->get($url)->throw()->json();

        if (! is_array($document) || ($document['id'] ?? null) !== $did) {
            throw new RuntimeException("The DID document of [{$did}] does not describe it.");
        }

        return $document;
    }

    /**
     * The PDS URL in a DID document.
     *
     * @param  array<string, mixed>  $document
     */
    public static function pdsOf(array $document): string
    {
        foreach ($document['service'] ?? [] as $service) {
            if (str_ends_with((string) ($service['id'] ?? ''), '#atproto_pds') && is_string($service['serviceEndpoint'] ?? null)) {
                return self::publicUrl(rtrim($service['serviceEndpoint'], '/'), 'The PDS');
            }
        }

        throw new RuntimeException('The DID document names no PDS.');
    }

    /**
     * The handle a DID document claims (at://alice.bsky.social), if any.
     *
     * @param  array<string, mixed>  $document
     */
    public static function handleOf(array $document): ?string
    {
        foreach ($document['alsoKnownAs'] ?? [] as $alias) {
            if (is_string($alias) && str_starts_with($alias, 'at://')) {
                return substr($alias, 5);
            }
        }

        return null;
    }

    /**
     * The issuer of the authorization server a PDS delegates to. A server with
     * no protected resource metadata is taken as its own authorization server
     * (the entryway, bsky.social): for a PDS, that is never the issuer that
     * signed an account in, so the callback's check still refuses it.
     */
    public static function authorizationServerOf(string $pds): string
    {
        return Cache::remember('social-tokens:atproto-as:'.$pds, now()->addHour(), function () use ($pds) {
            self::publicUrl($pds, 'The PDS');
            $response = self::http()->get(rtrim($pds, '/').'/.well-known/oauth-protected-resource');

            if ($response->notFound()) {
                return rtrim($pds, '/');
            }

            $issuer = $response->throw()->json('authorization_servers.0');

            if (! is_string($issuer)) {
                throw new RuntimeException("[{$pds}] names no authorization server.");
            }

            return self::publicUrl(rtrim($issuer, '/'), "The authorization server of [{$pds}]");
        });
    }

    /**
     * An authorization server's metadata, checked to be its own (the issuer
     * it states is the one asked).
     *
     * @return array<string, mixed>
     */
    public static function serverMetadata(string $issuer): array
    {
        return Cache::remember('social-tokens:atproto-asm:'.$issuer, now()->addHour(), function () use ($issuer) {
            self::publicUrl($issuer, 'The authorization server');
            $metadata = self::http()->get($issuer.'/.well-known/oauth-authorization-server')->throw()->json();

            if (! is_array($metadata) || rtrim((string) ($metadata['issuer'] ?? ''), '/') !== $issuer) {
                throw new RuntimeException("[{$issuer}] answered with another issuer's metadata.");
            }

            foreach (['authorization_endpoint', 'token_endpoint', 'pushed_authorization_request_endpoint'] as $endpoint) {
                if (! is_string($metadata[$endpoint] ?? null)) {
                    throw new RuntimeException("[{$issuer}] has no {$endpoint}.");
                }
            }

            // The app posts to these (PAR, token, revocation), and keeps the
            // last two on the credential for every renewal.
            foreach (['authorization_endpoint', 'token_endpoint', 'pushed_authorization_request_endpoint', 'revocation_endpoint'] as $endpoint) {
                if (isset($metadata[$endpoint])) {
                    self::publicUrl((string) $metadata[$endpoint], "The {$endpoint} of [{$issuer}]");
                }
            }

            return $metadata;
        });
    }

    /**
     * A public profile (handle, displayName, avatar, counts) from the AppView.
     *
     * @return array<string, mixed>
     */
    public static function profile(string $did): array
    {
        $response = self::http()->get(self::PUBLIC_APPVIEW.'/xrpc/app.bsky.actor.getProfile', ['actor' => $did]);

        return $response->successful() ? (array) $response->json() : [];
    }

    /**
     * $url, when it is safe for the app's server to fetch: https, a host name
     * (not a bare word, nor a local one such as localhost or *.internal) or a
     * public address, and every address the name resolves to public. Throws
     * otherwise. A name that resolves to nothing passes: the request fails on
     * its own. Checked at resolution time, not at connection time: DNS that
     * changes its answer in between (rebinding) is not covered.
     */
    public static function publicUrl(string $url, string $what = 'The URL'): string
    {
        $parts = parse_url($url);
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (($parts['scheme'] ?? null) !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException("{$what} must be an https URL; got [{$url}].");
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $addresses = [$host];
        } elseif (! str_contains($host, '.') || preg_match('/\.(localhost|local|localdomain|internal|intranet|lan|home\.arpa)$/', '.'.$host) === 1) {
            throw new RuntimeException("{$what} is on a local host [{$host}].");
        } else {
            $addresses = (self::$resolver ?? self::resolve(...))($host);
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE) === false) {
                throw new RuntimeException("{$what} [{$host}] is not a public address.");
            }
        }

        return $url;
    }

    /**
     * Replace how host names resolve to addresses (null: DNS), for tests.
     *
     * @param  (callable(string): array<int, string>)|null  $resolver
     */
    public static function resolveHostsUsing(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function entryway(): string
    {
        return rtrim((string) (config('services.bluesky.entryway') ?: self::DEFAULT_ENTRYWAY), '/');
    }

    /** @return array<int, string> the IPv4 and IPv6 addresses of $host */
    private static function resolve(string $host): array
    {
        $ipv6 = @dns_get_record($host, DNS_AAAA);

        return [
            ...(gethostbynamel($host) ?: []),
            ...array_column(is_array($ipv6) ? $ipv6 : [], 'ipv6'),
        ];
    }

    private static function http(): PendingRequest
    {
        return Http::timeout(Settings::httpTimeout())->connectTimeout(Settings::connectTimeout())->acceptJson();
    }
}
