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
 * @see https://atproto.com/specs/oauth
 */
final class Atproto
{
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
            str_starts_with($did, 'did:web:') => 'https://'.rawurldecode(substr($did, 8)).'/.well-known/did.json',
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
                return rtrim($service['serviceEndpoint'], '/');
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
            $response = self::http()->get(rtrim($pds, '/').'/.well-known/oauth-protected-resource');

            if ($response->notFound()) {
                return rtrim($pds, '/');
            }

            $issuer = $response->throw()->json('authorization_servers.0');

            if (! is_string($issuer) || ! str_starts_with($issuer, 'https://')) {
                throw new RuntimeException("[{$pds}] names no authorization server.");
            }

            return rtrim($issuer, '/');
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
            $metadata = self::http()->get($issuer.'/.well-known/oauth-authorization-server')->throw()->json();

            if (! is_array($metadata) || rtrim((string) ($metadata['issuer'] ?? ''), '/') !== $issuer) {
                throw new RuntimeException("[{$issuer}] answered with another issuer's metadata.");
            }

            foreach (['authorization_endpoint', 'token_endpoint', 'pushed_authorization_request_endpoint'] as $endpoint) {
                if (! is_string($metadata[$endpoint] ?? null)) {
                    throw new RuntimeException("[{$issuer}] has no {$endpoint}.");
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

    public static function entryway(): string
    {
        return rtrim((string) (config('services.bluesky.entryway') ?: self::DEFAULT_ENTRYWAY), '/');
    }

    private static function http(): PendingRequest
    {
        return Http::timeout(Settings::httpTimeout())->connectTimeout(Settings::connectTimeout())->acceptJson();
    }
}
