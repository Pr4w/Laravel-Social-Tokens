<?php

namespace Pr4w\SocialTokens\Support;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * DPoP (RFC 9449): proof-of-possession for OAuth tokens, which AT Protocol
 * (Bluesky) requires on every request. Each credential has its own ES256 key
 * pair, generated at connect and stored encrypted with it (social_tokens
 * dpop_key): its tokens are bound to that key, so the token alone is useless.
 *
 * Every request carries a proof, a JWT signed with the key, naming the method
 * and URL. Servers also require a nonce they hand out (DPoP-Nonce) and rotate:
 * the last one seen is cached per server, and a request refused for a missing
 * or stale nonce (use_dpop_nonce) is sent once more with the new one.
 *
 * Plain openssl, no JWT library: an ES256 signature is the DER one openssl
 * makes, converted to the raw R || S the JWS format wants.
 */
final class DPoP
{
    /** A new P-256 private key, PEM encoded. */
    public static function generateKey(): string
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        if ($key === false || ! openssl_pkey_export($key, $pem)) {
            throw new RuntimeException('Could not generate a DPoP key: '.openssl_error_string());
        }

        return $pem;
    }

    /**
     * The public JWK of a P-256 key, as servers want it in a proof's header or
     * a client's JWKS.
     *
     * @return array{kty: string, crv: string, x: string, y: string}
     */
    public static function publicJwk(#[\SensitiveParameter] string $pem): array
    {
        $details = openssl_pkey_get_details(self::key($pem));

        if ($details === false || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
            throw new RuntimeException('DPoP and client keys must be P-256 (ES256) keys.');
        }

        return [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => self::base64url(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)),
            'y' => self::base64url(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT)),
        ];
    }

    /**
     * A compact ES256 JWS.
     *
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $payload
     */
    public static function sign(#[\SensitiveParameter] string $pem, array $header, array $payload): string
    {
        $input = self::base64url(json_encode(['alg' => 'ES256'] + $header, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
            .'.'.self::base64url(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        if (! openssl_sign($input, $der, self::key($pem), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign: '.openssl_error_string());
        }

        return $input.'.'.self::base64url(self::derToRaw($der));
    }

    /**
     * The proof for one request. $accessToken binds it to the token it is sent
     * with (ath), for resource requests; leave it out at the token endpoint.
     */
    public static function proof(
        #[\SensitiveParameter] string $pem,
        string $method,
        string $url,
        ?string $nonce = null,
        #[\SensitiveParameter] ?string $accessToken = null,
    ): string {
        return self::sign($pem, ['typ' => 'dpop+jwt', 'jwk' => self::publicJwk($pem)], array_filter([
            'jti' => (string) Str::uuid(),
            'htm' => strtoupper($method),
            'htu' => self::htu($url),
            'iat' => time(),
            'nonce' => $nonce,
            'ath' => $accessToken !== null ? self::base64url(hash('sha256', $accessToken, true)) : null,
        ], fn ($value) => $value !== null));
    }

    /**
     * Guzzle middleware that signs every request with $pem (Laravel:
     * Http::withMiddleware(DPoP::middleware($key, $token))). With $accessToken
     * it also sets "Authorization: DPoP {token}". Handles the server nonce:
     * cached per origin, and one retry when the server asks for a new one.
     */
    public static function middleware(#[\SensitiveParameter] string $pem, #[\SensitiveParameter] ?string $accessToken = null): callable
    {
        return static function (callable $handler) use ($pem, $accessToken): callable {
            return static function (RequestInterface $request, array $options) use ($handler, $pem, $accessToken): PromiseInterface {
                $send = static function (RequestInterface $request) use ($handler, $options, $pem, $accessToken): PromiseInterface {
                    $url = (string) $request->getUri();
                    $signed = $request->withHeader('DPoP', self::proof($pem, $request->getMethod(), $url, self::nonce($url), $accessToken));

                    if ($accessToken !== null) {
                        $signed = $signed->withHeader('Authorization', 'DPoP '.$accessToken);
                    }

                    return $handler($signed, $options)->then(static function (ResponseInterface $response) use ($url) {
                        if ($response->hasHeader('DPoP-Nonce')) {
                            self::rememberNonce($url, $response->getHeaderLine('DPoP-Nonce'));
                        }

                        return $response;
                    });
                };

                return $send($request)->then(static function (ResponseInterface $response) use ($send, $request): PromiseInterface {
                    if (! self::asksForNonce($response)) {
                        return Create::promiseFor($response);
                    }

                    $request->getBody()->isSeekable() && $request->getBody()->rewind();

                    return $send($request);
                });
            };
        };
    }

    /** Whether a response refuses the request for want of a (new) nonce. */
    public static function asksForNonce(ResponseInterface $response): bool
    {
        if (! $response->hasHeader('DPoP-Nonce')) {
            return false;
        }

        // A resource server says it in WWW-Authenticate (401), an authorization
        // server in the error body (400)
        if (str_contains($response->getHeaderLine('WWW-Authenticate'), 'use_dpop_nonce')) {
            return true;
        }

        if ($response->getStatusCode() !== 400) {
            return false;
        }

        $body = (string) $response->getBody();
        $response->getBody()->isSeekable() && $response->getBody()->rewind();

        return (json_decode($body, true)['error'] ?? null) === 'use_dpop_nonce';
    }

    public static function nonce(string $url): ?string
    {
        return Cache::get(self::nonceKey($url));
    }

    public static function rememberNonce(string $url, string $nonce): void
    {
        // Servers rotate them every few minutes and accept the previous ones a while
        Cache::put(self::nonceKey($url), $nonce, now()->addMinutes(5));
    }

    public static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** The URL a proof names: no query, no fragment. */
    private static function htu(string $url): string
    {
        return preg_replace('/[?#].*$/', '', $url) ?? $url;
    }

    private static function nonceKey(string $url): string
    {
        $parts = parse_url($url);

        return 'social-tokens:dpop-nonce:'.($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    private static function key(#[\SensitiveParameter] string $pem): \OpenSSLAsymmetricKey
    {
        return openssl_pkey_get_private($pem) ?: throw new RuntimeException('Not a usable private key.');
    }

    /** openssl's DER ECDSA signature (SEQUENCE of two INTEGERs) → the raw 64 bytes JWS wants. */
    private static function derToRaw(string $der): string
    {
        $offset = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7F : 0);
        $raw = '';

        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            $integer = substr($der, $offset + 2, $length);
            $raw .= str_pad(ltrim($integer, "\0"), 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $raw;
    }
}
