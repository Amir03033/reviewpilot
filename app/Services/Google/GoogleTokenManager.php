<?php

namespace App\Services\Google;

use App\Enums\ConnectionStatus;
use App\Enums\OrganizationRole;
use App\Models\GoogleConnection;
use App\Models\OrganizationMembership;
use App\Notifications\GoogleReconnectRequired;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

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

        // Tell everyone who can fix it (owners and admins), not only the person who
        // originally connected Google; they may have left the company.
        // Members can switch this email off in their notification settings.
        $memberships = OrganizationMembership::withoutGlobalScopes()
            ->where('organization_id', $connection->organization_id)
            ->with('user')->get();
        $recipients = $memberships
            ->filter(fn (OrganizationMembership $m) => in_array($m->role, [OrganizationRole::Owner, OrganizationRole::Admin], true)
                || $m->user_id === $connection->connected_by)
            ->filter(fn (OrganizationMembership $m) => $m->wantsEmail('google_reconnect'))
            ->pluck('user')->filter()->unique('id');
        Notification::send($recipients, new GoogleReconnectRequired($connection));

        throw new GoogleReauthRequired($reason);
    }
}
