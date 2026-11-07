<?php

namespace App\Services\Google;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * OAuth 2.0 Authorization Code flow with PKCE for the Business Profile connection.
 */
class GoogleOAuth
{
    public function isConfigured(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    /** @return array{verifier: string, challenge: string} */
    public function pkcePair(): array
    {
        $verifier = Str::random(96);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return ['verifier' => $verifier, 'challenge' => $challenge];
    }

    public function authorizationUrl(string $state, string $codeChallenge, ?string $loginHint = null): string
    {
        $query = array_filter([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.redirect'),
            'response_type' => 'code',
            'scope' => implode(' ', config('services.google.scopes')),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'login_hint' => $loginHint,
        ]);

        return config('services.google.endpoints.auth').'?'.http_build_query($query);
    }

    /**
     * @return array{access_token: string, refresh_token: ?string, expires_in: int, scope: string, sub: string, email: ?string}
     */
    public function exchangeCode(string $code, string $verifier): array
    {
        $response = Http::asForm()->acceptJson()->post(config('services.google.endpoints.token'), [
            'code' => $code,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => config('services.google.redirect'),
            'grant_type' => 'authorization_code',
            'code_verifier' => $verifier,
        ]);

        if ($response->failed()) {
            throw new GoogleApiException('Google token exchange failed: '.$response->json('error', 'unknown'), $response->status(), $response->json());
        }

        $tokens = $response->json();

        $userinfo = Http::withToken($tokens['access_token'])->acceptJson()->get(config('services.google.endpoints.userinfo'));

        if ($userinfo->failed() || ! $userinfo->json('sub')) {
            throw new GoogleApiException('Could not read Google account identity.', $userinfo->status(), $userinfo->json());
        }

        return [
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? null,
            'expires_in' => (int) ($tokens['expires_in'] ?? 3600),
            'scope' => (string) ($tokens['scope'] ?? ''),
            'sub' => (string) $userinfo->json('sub'),
            'email' => $userinfo->json('email'),
        ];
    }

    /**
     * @return array{access_token: string, expires_in: int}
     */
    public function refresh(string $refreshToken): array
    {
        $response = Http::asForm()->acceptJson()->post(config('services.google.endpoints.token'), [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if ($response->json('error') === 'invalid_grant') {
            throw new GoogleReauthRequired('Refresh token was revoked or expired.');
        }

        if ($response->failed() || blank($response->json('access_token'))) {
            throw new GoogleApiException('Google token refresh failed.', $response->status(), $response->json());
        }

        return [
            'access_token' => (string) $response->json('access_token'),
            'expires_in' => (int) $response->json('expires_in', 3600),
        ];
    }

    public function revoke(string $token): void
    {
        Http::asForm()->post(config('services.google.endpoints.revoke'), ['token' => $token]);
    }
}
