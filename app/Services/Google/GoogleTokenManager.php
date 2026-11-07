<?php

namespace App\Services\Google;

use App\Enums\ConnectionStatus;
use App\Models\GoogleConnection;
use App\Notifications\GoogleReconnectRequired;
use Illuminate\Support\Facades\Cache;

class GoogleTokenManager
{
    public function __construct(private GoogleOAuth $oauth) {}

    public function accessToken(GoogleConnection $connection): string
    {
        if ($connection->status !== ConnectionStatus::Active) {
            throw new GoogleReauthRequired('Google connection is not active.');
        }

        if ($this->isFresh($connection)) {
            return $connection->access_token;
        }

        return Cache::lock('google-refresh:'.$connection->id, 30)->block(20, function () use ($connection) {
            $connection->refresh();

            if ($this->isFresh($connection)) {
                return $connection->access_token;
            }

            if (blank($connection->refresh_token)) {
                $this->markNeedsReauth($connection, 'No refresh token stored.');
            }

            try {
                $tokens = $this->oauth->refresh($connection->refresh_token);
            } catch (GoogleReauthRequired $e) {
                $this->markNeedsReauth($connection, $e->getMessage());
            }

            $connection->forceFill([
                'access_token' => $tokens['access_token'],
                'access_token_expires_at' => now()->addSeconds($tokens['expires_in']),
                'last_refreshed_at' => now(),
                'last_error' => null,
            ])->save();

            return $tokens['access_token'];
        });
    }

    public function forceRefresh(GoogleConnection $connection): string
    {
        $connection->forceFill(['access_token_expires_at' => now()->subMinute()])->save();

        return $this->accessToken($connection);
    }

    private function isFresh(GoogleConnection $connection): bool
    {
        return filled($connection->access_token)
            && $connection->access_token_expires_at !== null
            && $connection->access_token_expires_at->isAfter(now()->addMinutes(5));
    }

    private function markNeedsReauth(GoogleConnection $connection, string $reason): never
    {
        $connection->forceFill(['status' => ConnectionStatus::NeedsReauth, 'last_error' => $reason])->save();

        $connection->connectedBy?->notify(new GoogleReconnectRequired($connection));

        throw new GoogleReauthRequired($reason);
    }
}
