<?php

namespace Pr4w\SocialTokens\Support;

use Pr4w\SocialTokens\Exceptions\InvalidSignedRequestException;

/**
 * Meta's signed_request, as posted to an app's Deauthorize and Data Deletion
 * Request callbacks (Facebook Login, Instagram Login) and to Threads'
 * Uninstall and Delete callbacks: two base64url parts joined by a dot, an
 * HMAC-SHA256 signature of the second part keyed with the app secret, and a
 * JSON payload ({"algorithm": "HMAC-SHA256", "issued_at": ..., "user_id": ...}).
 *
 * Anyone can post to those URLs: nothing in the payload is trusted until the
 * signature checks out, compared in constant time.
 */
final class MetaSignedRequest
{
    /**
     * The verified payload.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidSignedRequestException
     */
    public static function parse(string $signedRequest, #[\SensitiveParameter] string $secret): array
    {
        if ($secret === '') {
            throw new InvalidSignedRequestException('No app secret to verify the signed_request with.');
        }

        $parts = explode('.', trim($signedRequest));

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidSignedRequestException('Malformed signed_request.');
        }

        [$encodedSignature, $encodedPayload] = $parts;

        $signature = self::decode($encodedSignature);
        $payload = json_decode((string) self::decode($encodedPayload), true);

        if ($signature === null || ! is_array($payload)) {
            throw new InvalidSignedRequestException('Malformed signed_request.');
        }

        if (strtoupper((string) ($payload['algorithm'] ?? '')) !== 'HMAC-SHA256') {
            throw new InvalidSignedRequestException('Unexpected signed_request algorithm.');
        }

        if (! hash_equals(hash_hmac('sha256', $encodedPayload, $secret, true), $signature)) {
            throw new InvalidSignedRequestException('The signed_request signature does not match the app secret.');
        }

        return $payload;
    }

    /**
     * A signed_request for $payload, as Meta builds it. For tests and tools.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function make(array $payload, #[\SensitiveParameter] string $secret): string
    {
        $encodedPayload = self::encode((string) json_encode($payload + ['algorithm' => 'HMAC-SHA256']));

        return self::encode(hash_hmac('sha256', $encodedPayload, $secret, true)).'.'.$encodedPayload;
    }

    private static function decode(string $value): ?string
    {
        if (! preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $value)) {
            return null;
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
