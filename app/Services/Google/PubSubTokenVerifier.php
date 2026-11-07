<?php

namespace App\Services\Google;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Verifies the OIDC JWT that Google Cloud Pub/Sub attaches to authenticated push
 * requests (Authorization: Bearer <jwt>).
 */
class PubSubTokenVerifier
{
    public function verify(?string $authorizationHeader): bool
    {
        $audience = config('services.google.pubsub_audience');
        $serviceAccount = config('services.google.pubsub_service_account');

        if (blank($audience) || blank($serviceAccount)) {
            return false; // Not configured: refuse everything rather than accept unauthenticated pushes.
        }

        if (! is_string($authorizationHeader) || ! str_starts_with($authorizationHeader, 'Bearer ')) {
            return false;
        }

        $parts = explode('.', substr($authorizationHeader, 7));
        if (count($parts) !== 3) {
            return false;
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $header = json_decode($this->b64($encodedHeader), true);
        $payload = json_decode($this->b64($encodedPayload), true);

        if (! is_array($header) || ! is_array($payload) || ($header['alg'] ?? null) !== 'RS256') {
            return false;
        }

        $kid = (string) ($header['kid'] ?? '');
        $cert = $this->certificates()[$kid] ?? null;
        if (! $cert) {
            // Google rotates its signing keys: refresh the cached certificates once.
            $cert = $this->certificates(refresh: true)[$kid] ?? null;
        }
        if (! $cert) {
            return false;
        }

        $valid = openssl_verify("{$encodedHeader}.{$encodedPayload}", $this->b64($encodedSignature), $cert, OPENSSL_ALGO_SHA256) === 1;

        return $valid
            && in_array($payload['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)
            && ($payload['aud'] ?? null) === $audience
            && ($payload['email'] ?? null) === $serviceAccount
            && ($payload['email_verified'] ?? false) === true
            && (int) ($payload['exp'] ?? 0) > time();
    }

    /** @return array<string, string> kid => PEM certificate */
    private function certificates(bool $refresh = false): array
    {
        if ($refresh) {
            if (! Cache::add('google-oidc-certs-refreshed', true, 60)) {
                return Cache::get('google-oidc-certs', []); // refreshed less than a minute ago
            }
            Cache::forget('google-oidc-certs');
        }

        $cached = Cache::get('google-oidc-certs');
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $response = rescue(fn () => Http::acceptJson()->timeout(10)->get(config('services.google.endpoints.certs')), report: false);
        $certs = $response?->successful() ? (array) $response->json() : [];

        if ($certs !== []) {
            // Never cache a failed fetch, or every push would be rejected for an hour.
            Cache::put('google-oidc-certs', $certs, now()->addHour());
        }

        return $certs;
    }

    private function b64(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4));
    }
}
