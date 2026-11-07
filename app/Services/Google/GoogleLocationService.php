<?php

namespace App\Services\Google;

use App\Enums\ConnectionStatus;
use App\Jobs\SyncLocationReviews;
use App\Models\GbpAccount;
use App\Models\GbpLocationLink;
use App\Models\GoogleConnection;
use App\Models\Location;
use App\Models\LocationFact;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\BillingService;
use Illuminate\Support\Facades\DB;

/**
 * Connecting Google accounts, discovering GBP locations and importing them.
 */
class GoogleLocationService
{
    public function __construct(
        private GbpClient $client,
        private AuditLogger $audit,
        private GoogleOAuth $oauth,
    ) {}

    /**
     * @param  array{access_token: string, refresh_token: ?string, expires_in: int, scope: string, sub: string, email: ?string}  $tokens
     */
    public function storeConnection(Organization $organization, User $user, array $tokens): GoogleConnection
    {
        $connection = GoogleConnection::withoutGlobalScopes()->firstOrNew([
            'organization_id' => $organization->id,
            'google_sub' => $tokens['sub'],
        ]);

        $connection->forceFill([
            'connected_by' => $user->id,
            'google_email' => $tokens['email'],
            'access_token' => $tokens['access_token'],
            'access_token_expires_at' => now()->addSeconds($tokens['expires_in']),
            // Google only returns a refresh token on first consent; keep the old one otherwise.
            'refresh_token' => $tokens['refresh_token'] ?? $connection->refresh_token,
            'scopes' => array_values(array_filter(explode(' ', $tokens['scope']))),
            'status' => ConnectionStatus::Active,
            'last_error' => null,
        ])->save();

        // Reconnecting after a disconnect: resume syncing the locations this account manages.
        $locationIds = GbpLocationLink::withoutGlobalScopes()
            ->whereIn('gbp_account_id', GbpAccount::withoutGlobalScopes()->where('google_connection_id', $connection->id)->select('id'))
            ->whereNotNull('location_id')
            ->pluck('location_id');
        Location::withoutGlobalScopes()->whereIn('id', $locationIds)->where('status', 'disconnected')->update(['status' => 'active']);

        $this->audit->log('google.connected', $connection, ['email' => $tokens['email']]);

        return $connection;
    }

    /** Fetch accounts and locations from Google and store them as importable links. */
    public function discover(GoogleConnection $connection): int
    {
        $count = 0;

        foreach ($this->client->listAccounts($connection) as $rawAccount) {
            $account = GbpAccount::withoutGlobalScopes()->updateOrCreate(
                ['google_connection_id' => $connection->id, 'account_name' => $rawAccount['name']],
                [
                    'organization_id' => $connection->organization_id,
                    'display_name' => $rawAccount['accountName'] ?? null,
                    'type' => $rawAccount['type'] ?? null,
                    'role' => $rawAccount['role'] ?? null,
                ],
            );

            foreach ($this->client->listLocations($connection, $account->account_name) as $raw) {
                $locationName = (string) $raw['name']; // locations/{id}
                $address = $raw['storefrontAddress'] ?? [];

                GbpLocationLink::withoutGlobalScopes()->updateOrCreate(
                    ['gbp_account_id' => $account->id, 'gbp_location_name' => $locationName],
                    [
                        'organization_id' => $connection->organization_id,
                        'v4_parent' => $account->account_name.'/'.$locationName,
                        'title' => $raw['title'] ?? $locationName,
                        'address' => trim(implode(', ', array_filter([implode(' ', $address['addressLines'] ?? []), $address['locality'] ?? null]))) ?: null,
                        'place_id' => $raw['metadata']['placeId'] ?? null,
                        // hasVoiceOfMerchant = verified and under the merchant's control.
                        'is_verified' => (bool) ($raw['metadata']['hasVoiceOfMerchant'] ?? false),
                        'raw' => $raw,
                    ],
                );
                $count++;
            }
        }

        return $count;
    }

    /** Create a ReviewPilot location from a discovered GBP location and start the first import. */
    public function import(GbpLocationLink $link): Location
    {
        $location = DB::transaction(function () use ($link) {
            $taken = GbpLocationLink::withoutGlobalScopes()
                ->where('gbp_location_name', $link->gbp_location_name)
                ->whereNotNull('location_id')
                ->where('id', '!=', $link->id)
                ->exists();

            if ($taken) {
                throw new \InvalidArgumentException('This Google location is already managed in ReviewPilot.');
            }

            if ($link->location_id) {
                return Location::withoutGlobalScopes()->findOrFail($link->location_id);
            }

            $raw = $link->raw ?? [];
            $address = $raw['storefrontAddress'] ?? [];

            $location = Location::withoutGlobalScopes()->create([
                'organization_id' => $link->organization_id,
                'name' => $link->title,
                'address_line' => implode(' ', $address['addressLines'] ?? []) ?: null,
                'city' => $address['locality'] ?? null,
                'postal_code' => $address['postalCode'] ?? null,
                'country' => $address['regionCode'] ?? 'NL',
                'primary_category' => $raw['categories']['primaryCategory']['displayName'] ?? null,
                'automation_mode' => config('reviewpilot.automation.default_mode'),
            ]);

            $facts = array_filter([
                'address' => $link->address,
                'website' => $raw['websiteUri'] ?? null,
                'phone' => $raw['phoneNumbers']['primaryPhone'] ?? null,
            ]);
            foreach ($facts as $key => $value) {
                LocationFact::withoutGlobalScopes()->create([
                    'organization_id' => $link->organization_id,
                    'location_id' => $location->id,
                    'key' => $key,
                    'value' => $value,
                    'source' => 'gbp',
                ]);
            }

            $link->forceFill(['location_id' => $location->id, 'sync_state' => 'backfilling'])->save();
            $this->audit->log('location.imported', $location, ['gbp' => $link->gbp_location_name]);

            return $location;
        });

        SyncLocationReviews::dispatch($link->id, 'backfill');
        $this->registerNotifications($link->account);
        app(BillingService::class)->syncQuantity(Organization::findOrFail($link->organization_id));

        return $location;
    }

    /** Ask Google to publish NEW_REVIEW / UPDATED_REVIEW events for this account to our Pub/Sub topic. */
    public function registerNotifications(GbpAccount $account): void
    {
        $topic = config('services.google.pubsub_topic');

        if (blank($topic) || $account->notifications_registered_at) {
            return;
        }

        try {
            $this->client->updateNotificationSetting($account->connection, $account->account_name, $topic);
            $account->forceFill(['notifications_registered_at' => now()])->save();
        } catch (GoogleApiException $e) {
            report($e); // polling keeps working without push notifications
        }
    }

    public function disconnect(GoogleConnection $connection): void
    {
        if ($connection->refresh_token) {
            rescue(fn () => $this->oauth->revoke($connection->refresh_token), report: false);
        }

        DB::transaction(function () use ($connection) {
            $linkIds = GbpLocationLink::withoutGlobalScopes()
                ->whereIn('gbp_account_id', $connection->accounts()->withoutGlobalScopes()->select('id'))
                ->pluck('location_id')->filter();

            Location::withoutGlobalScopes()->whereIn('id', $linkIds)->update(['status' => 'disconnected']);

            $connection->forceFill([
                'status' => ConnectionStatus::Revoked,
                'access_token' => null,
                'refresh_token' => null,
            ])->save();
        });

        $this->audit->log('google.disconnected', $connection);
    }
}
