<?php

namespace Tests\Feature\ReviewPilot;

use App\Enums\ConnectionStatus;
use App\Jobs\SyncLocationReviews;
use App\Models\GbpLocationLink;
use App\Models\GoogleConnection;
use App\Models\Location;
use App\Models\Review;
use App\Services\Google\GbpClient;
use App\Services\Google\GoogleReauthRequired;
use App\Services\Google\GoogleTokenManager;
use App\Services\Reviews\ReviewIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class GoogleIntegrationTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.google.client_id' => 'client-id',
            'services.google.client_secret' => 'client-secret',
            'services.google.redirect' => 'http://localhost/integrations/google/callback',
        ]);
        Notification::fake();
    }

    public function test_connect_redirects_to_google_with_pkce_and_offline_access(): void
    {
        [$owner] = $this->makeOrganization();

        $response = $this->actingAs($owner)->post(route('integrations.google.connect'));

        $location = $response->headers->get('Location') ?? $response->headers->get('X-Inertia-Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertStringContainsString('https://www.googleapis.com/auth/business.manage', $query['scope']);
    }

    public function test_callback_stores_encrypted_tokens_and_discovers_locations(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.new', 'refresh_token' => '1//r', 'expires_in' => 3599, 'scope' => 'openid email https://www.googleapis.com/auth/business.manage']),
            'openidconnect.googleapis.com/*' => Http::response(['sub' => 'google-sub-1', 'email' => 'owner@bistro.nl']),
            'mybusinessaccountmanagement.googleapis.com/v1/accounts*' => Http::response(['accounts' => [['name' => 'accounts/42', 'accountName' => 'Bistro BV', 'type' => 'ORGANIZATION', 'role' => 'OWNER']]]),
            'mybusinessbusinessinformation.googleapis.com/v1/accounts/42/locations*' => Http::response(['locations' => [
                ['name' => 'locations/9', 'title' => 'Bistro Utrecht', 'storefrontAddress' => ['addressLines' => ['Oudegracht 1'], 'locality' => 'Utrecht', 'regionCode' => 'NL'], 'metadata' => ['hasVoiceOfMerchant' => true, 'placeId' => 'ChIJ123'], 'websiteUri' => 'https://bistro.nl'],
                ['name' => 'locations/10', 'title' => 'Bistro Unverified', 'metadata' => ['hasVoiceOfMerchant' => false]],
            ]]),
        ]);

        [$owner, $org] = $this->makeOrganization();
        $this->actingAs($owner)->withSession(['google_oauth' => ['state' => 'abc', 'verifier' => 'v', 'organization_id' => $org->id]])
            ->get(route('integrations.google.callback', ['state' => 'abc', 'code' => 'code-1']))
            ->assertRedirect(route('integrations.index'));

        $connection = GoogleConnection::withoutGlobalScopes()->where('organization_id', $org->id)->firstOrFail();
        $this->assertSame('ya29.new', $connection->access_token);
        $this->assertNotSame('ya29.new', $connection->getRawOriginal('access_token'), 'token must be encrypted at rest');

        $links = GbpLocationLink::withoutGlobalScopes()->where('organization_id', $org->id)->get();
        $this->assertCount(2, $links);
        $verified = $links->firstWhere('gbp_location_name', 'locations/9');
        $this->assertTrue($verified->is_verified);
        $this->assertSame('accounts/42/locations/9', $verified->v4_parent);

        // Import the verified location
        Queue::fake();
        $this->actingAs($owner)->post(route('integrations.google.import'), ['links' => $links->pluck('id')->all()]);

        $location = Location::withoutGlobalScopes()->where('organization_id', $org->id)->sole();
        $this->assertSame('Bistro Utrecht', $location->name);
        $this->assertSame($location->id, $verified->fresh()->location_id);
        $this->assertNull($links->firstWhere('gbp_location_name', 'locations/10')->fresh()->location_id);
        Queue::assertPushed(SyncLocationReviews::class, fn ($job) => $job->mode === 'backfill');
    }

    public function test_callback_rejects_a_mismatched_state(): void
    {
        Http::fake();
        [$owner, $org] = $this->makeOrganization();

        $this->actingAs($owner)->withSession(['google_oauth' => ['state' => 'abc', 'verifier' => 'v', 'organization_id' => $org->id]])
            ->get(route('integrations.google.callback', ['state' => 'evil', 'code' => 'x']))
            ->assertRedirect(route('integrations.index'));

        Http::assertNothingSent();
        $this->assertSame(0, GoogleConnection::withoutGlobalScopes()->count());
    }

    public function test_sync_ingests_reviews_and_detects_edits_and_external_replies(): void
    {
        [, $org] = $this->makeOrganization();
        $location = $this->makeLocation($org);
        $link = $location->gbpLink()->withoutGlobalScopes()->first();
        $base = "{$link->v4_parent}/reviews";

        Http::fakeSequence('mybusiness.googleapis.com/*')
            ->push(['reviews' => [
                ['name' => "{$base}/r1", 'reviewId' => 'r1', 'reviewer' => ['displayName' => 'Anna'], 'starRating' => 'FIVE', 'comment' => 'Geweldig!', 'createTime' => now()->subHour()->toIso8601String(), 'updateTime' => now()->subHour()->toIso8601String()],
                ['name' => "{$base}/r2", 'reviewId' => 'r2', 'reviewer' => ['displayName' => 'Bob'], 'starRating' => 'TWO', 'comment' => '(Translated by Google) Cold food (Original) Koud eten', 'createTime' => now()->subHours(2)->toIso8601String(), 'updateTime' => now()->subHours(2)->toIso8601String(), 'reviewReply' => ['comment' => 'Sorry Bob', 'updateTime' => now()->toIso8601String()]],
            ], 'totalReviewCount' => 2])
            ->push(['reviews' => [
                ['name' => "{$base}/r1", 'reviewId' => 'r1', 'reviewer' => ['displayName' => 'Anna'], 'starRating' => 'THREE', 'comment' => 'Toch maar 3 sterren', 'createTime' => now()->subHour()->toIso8601String(), 'updateTime' => now()->addMinute()->toIso8601String()],
            ]]);

        (new SyncLocationReviews($link->id, 'backfill'))->handle(app(GbpClient::class), app(ReviewIngestor::class));

        $this->assertSame(2, Review::withoutGlobalScopes()->where('location_id', $location->id)->count());
        $r2 = Review::withoutGlobalScopes()->where('external_id', 'r2')->first();
        $this->assertSame('Koud eten', $r2->comment);
        $this->assertSame('external', $r2->owner_reply_origin);
        $this->assertSame('no_action', $r2->workflow_state->value);

        $this->travel(5)->minutes();
        (new SyncLocationReviews($link->id, 'poll'))->handle(app(GbpClient::class), app(ReviewIngestor::class));

        $r1 = Review::withoutGlobalScopes()->where('external_id', 'r1')->first();
        $this->assertSame(3, $r1->star_rating);
        $this->assertSame(1, $r1->revisions()->withoutGlobalScopes()->count());
    }

    public function test_revoked_refresh_token_marks_connection_for_reauth(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        [, $org] = $this->makeOrganization();
        $this->makeLocation($org);
        $connection = GoogleConnection::withoutGlobalScopes()->where('organization_id', $org->id)->first();
        $connection->forceFill(['access_token_expires_at' => now()->subMinute()])->save();

        try {
            app(GoogleTokenManager::class)->accessToken($connection);
            $this->fail('Expected GoogleReauthRequired');
        } catch (GoogleReauthRequired) {
        }

        $this->assertSame(ConnectionStatus::NeedsReauth, $connection->fresh()->status);
    }
}
