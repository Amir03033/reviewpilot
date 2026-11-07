<?php

namespace Tests\Concerns;

use App\Enums\AutomationMode;
use App\Models\GbpAccount;
use App\Models\GbpLocationLink;
use App\Models\GoogleConnection;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Review;
use App\Models\User;
use App\Services\Organizations\CreateOrganization;
use App\Support\Tenancy\TenantContext;
use App\Support\TopicTaxonomy;
use Illuminate\Support\Str;

trait BuildsTenants
{
    protected function makeOrganization(?User $owner = null, string $name = 'Test Bistro'): array
    {
        TopicTaxonomy::seed();
        $owner ??= User::factory()->create();
        $organization = app(CreateOrganization::class)->handle($owner, $name);

        return [$owner->fresh(), $organization];
    }

    protected function makeLocation(Organization $organization, AutomationMode $mode = AutomationMode::Smart, bool $connected = true): Location
    {
        $location = Location::withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'name' => 'Bistro '.Str::random(4),
            'city' => 'Utrecht',
            'automation_mode' => $mode,
        ]);

        if ($connected) {
            $connection = GoogleConnection::withoutGlobalScopes()->firstOrCreate(
                ['organization_id' => $organization->id, 'google_sub' => 'sub-'.$organization->id],
                [
                    'google_email' => 'owner@example.com',
                    'access_token' => 'ya29.test',
                    'access_token_expires_at' => now()->addDays(3),
                    'refresh_token' => '1//refresh',
                    'status' => 'active',
                ],
            );
            $account = GbpAccount::withoutGlobalScopes()->firstOrCreate(
                ['google_connection_id' => $connection->id, 'account_name' => 'accounts/111'],
                ['organization_id' => $organization->id],
            );
            $id = (string) random_int(1000, 999999);
            GbpLocationLink::withoutGlobalScopes()->create([
                'organization_id' => $organization->id,
                'gbp_account_id' => $account->id,
                'location_id' => $location->id,
                'gbp_location_name' => "locations/{$id}",
                'v4_parent' => "accounts/111/locations/{$id}",
                'title' => $location->name,
                'is_verified' => true,
                'sync_state' => 'active',
            ]);
        }

        return $location;
    }

    protected function makeReview(Location $location, int $rating = 5, ?string $comment = 'Heerlijk gegeten, top bediening!', array $attributes = []): Review
    {
        $id = Str::random(12);

        return Review::withoutGlobalScopes()->create([
            'organization_id' => $location->organization_id,
            'location_id' => $location->id,
            'external_id' => $id,
            'external_name' => $location->gbpLink?->v4_parent."/reviews/{$id}",
            'reviewer_display_name' => 'Jan de Vries',
            'star_rating' => $rating,
            'comment' => $comment,
            'created_at_source' => now()->subHour(),
            'updated_at_source' => now()->subHour(),
            ...$attributes,
        ]);
    }

    protected function inTenant(Organization $organization, callable $callback): mixed
    {
        return app(TenantContext::class)->run($organization, $callback);
    }
}
