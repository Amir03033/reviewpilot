<?php

namespace Tests\Feature\Production;

use App\Enums\AutomationMode;
use App\Enums\ConnectionStatus;
use App\Enums\ReplyState;
use App\Enums\ReviewState;
use App\Jobs\PublishReply;
use App\Jobs\SyncLocationReviews;
use App\Models\AiGeneration;
use App\Models\GbpAccount;
use App\Models\GbpLocationLink;
use App\Models\GoogleConnection;
use App\Models\Location;
use App\Models\Review;
use App\Models\ReviewReply;
use App\Models\UsageRecord;
use App\Notifications\GoogleReconnectRequired;
use App\Services\Google\GbpClient;
use App\Services\Google\GbpReview;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleLocationService;
use App\Services\Google\PubSubTokenVerifier;
use App\Services\Reviews\ReplyPublisher;
use App\Services\Reviews\ReviewIngestor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Google API failure modes, simulated with HTTP fakes (not the real API). */
class GoogleFailureTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);
    }

    private function gbp(string $id, int $stars = 5, ?string $updated = null, ?string $parent = null): array
    {
        $names = ['ONE', 'TWO', 'THREE', 'FOUR', 'FIVE'];

        return ['name' => "{$parent}/reviews/{$id}", 'reviewId' => $id, 'reviewer' => ['displayName' => 'Gast'], 'starRating' => $names[$stars - 1],
            'comment' => 'Lekker', 'createTime' => now()->subDays(2)->toIso8601String(), 'updateTime' => $updated ?? now()->subDays(2)->toIso8601String()];
    }

    private function runSync(string $linkId, string $mode = 'poll'): SyncLocationReviews
    {
        $job = (new SyncLocationReviews($linkId, $mode))->withFakeQueueInteractions();
        $job->handle(app(GbpClient::class), app(ReviewIngestor::class));

        return $job;
    }

    public function test_the_same_review_from_push_and_poll_is_stored_once(): void
    {
        [, $org] = $this->makeOrganization();
        $location = $this->makeLocation($org, AutomationMode::Manual);
        $data = GbpReview::fromApi($this->gbp('dup1', parent: $location->gbpLink->v4_parent));

        app(ReviewIngestor::class)->ingest($location, $data);
        app(ReviewIngestor::class)->ingest($location, $data);
        app(ReviewIngestor::class)->ingest($location, $data, isBackfill: true);

        $this->assertSame(1, Review::withoutGlobalScopes()->where('external_id', 'dup1')->count());

        $this->expectException(UniqueConstraintViolationException::class); // the DB is the last line of defence
        Review::withoutGlobalScopes()->create([...Review::withoutGlobalScopes()->where('external_id', 'dup1')->first()->only(['organization_id', 'location_id', 'source', 'external_id', 'external_name', 'star_rating', 'created_at_source', 'updated_at_source'])]);
    }

    public function test_a_google_location_can_only_be_managed_by_one_organization(): void
    {
        [, $orgA] = $this->makeOrganization(name: 'A');
        $locationA = $this->makeLocation($orgA);
        [, $orgB] = $this->makeOrganization(name: 'B');

        // Org B discovered the same Google location through another Google account.
        $linkA = $locationA->gbpLink;
        $connB = GoogleConnection::withoutGlobalScopes()->create(['organization_id' => $orgB->id, 'google_sub' => 'other', 'status' => 'active', 'access_token' => 't', 'access_token_expires_at' => now()->addDay()]);
        $accountB = GbpAccount::withoutGlobalScopes()->create(['organization_id' => $orgB->id, 'google_connection_id' => $connB->id, 'account_name' => 'accounts/999']);
        $linkB = GbpLocationLink::withoutGlobalScopes()->create(['organization_id' => $orgB->id, 'gbp_account_id' => $accountB->id, 'gbp_location_name' => $linkA->gbp_location_name, 'v4_parent' => 'accounts/999/'.$linkA->gbp_location_name, 'title' => 'Same place', 'is_verified' => true]);

        try {
            app(GoogleLocationService::class)->import($linkB);
            $this->fail('Import should be refused');
        } catch (\InvalidArgumentException) {
        }
        $this->assertSame(0, Location::withoutGlobalScopes()->where('organization_id', $orgB->id)->count());

        $this->expectException(UniqueConstraintViolationException::class);
        $locB = Location::withoutGlobalScopes()->create(['organization_id' => $orgB->id, 'name' => 'x']);
        $linkB->forceFill(['location_id' => $locB->id])->save();
    }

    public function test_rate_limit_releases_the_sync_job_without_losing_data(): void
    {
        [, $org] = $this->makeOrganization();
        $link = $this->makeLocation($org)->gbpLink;
        Http::fake(['mybusiness.googleapis.com/*' => Http::response(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED']], 429)]);

        $this->expectException(GoogleApiException::class);
        try {
            $this->runSync($link->id);
        } finally {
            $this->assertSame(0, Review::withoutGlobalScopes()->count());
            $this->assertNull($link->fresh()->last_review_update_time, 'cursor must not advance');
        }
    }

    public function test_our_own_quota_guard_releases_the_job(): void
    {
        [, $org] = $this->makeOrganization();
        $link = $this->makeLocation($org)->gbpLink;
        config(['reviewpilot.sync.gbp_requests_per_minute' => 1]);
        Http::fake(['mybusiness.googleapis.com/*' => Http::response(['reviews' => []])]);

        $this->runSync($link->id);
        $job = $this->runSync($link->id);

        $job->assertReleased();
    }

    public function test_lost_permission_marks_location_with_a_clear_error(): void
    {
        [, $org] = $this->makeOrganization();
        $link = $this->makeLocation($org)->gbpLink;
        Http::fake(['mybusiness.googleapis.com/*' => Http::response(['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'denied']], 403)]);

        $this->runSync($link->id);

        $this->assertSame('error', $link->fresh()->sync_state);
        $this->assertStringContainsString('no longer gives access', $link->fresh()->last_error);
    }

    public function test_expired_access_token_is_refreshed_once_on_401(): void
    {
        [, $org] = $this->makeOrganization();
        $link = $this->makeLocation($org)->gbpLink;
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.fresh', 'expires_in' => 3600]),
            'mybusiness.googleapis.com/*' => Http::sequence()
                ->push(['error' => ['code' => 401, 'status' => 'UNAUTHENTICATED']], 401)
                ->push(['reviews' => [$this->gbp('r9', parent: $link->v4_parent)]]),
        ]);

        $this->runSync($link->id, 'backfill');

        $this->assertSame(1, Review::withoutGlobalScopes()->count());
        $this->assertSame('ya29.fresh', GoogleConnection::withoutGlobalScopes()->first()->access_token);
    }

    public function test_revoked_access_stops_publishing_and_asks_to_reconnect(): void
    {
        [$owner, $org] = $this->makeOrganization();
        $location = $this->makeLocation($org, AutomationMode::Manual);
        GoogleConnection::withoutGlobalScopes()->update(['access_token_expires_at' => now()->subMinute()]);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        $review = $this->makeReview($location, 5);
        app(ReplyPublisher::class)->submitManual($review, $owner, 'Dank je!');

        $this->assertSame(ReviewState::PublishFailed, $review->fresh()->workflow_state);
        $this->assertSame(ConnectionStatus::NeedsReauth, GoogleConnection::withoutGlobalScopes()->first()->status);
        Notification::assertSentTo($owner, GoogleReconnectRequired::class);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'mybusiness.googleapis.com'));
    }

    public function test_server_errors_are_retried_and_then_marked_failed(): void
    {
        [$owner, $org] = $this->makeOrganization();
        $review = $this->makeReview($this->makeLocation($org, AutomationMode::Manual), 5);
        Http::fake(['mybusiness.googleapis.com/*' => Http::sequence()
            ->push(['error' => ['code' => 503, 'status' => 'UNAVAILABLE']], 503)
            ->push(['comment' => 'Dank je!'])]);

        Queue::fake();
        app(ReplyPublisher::class)->submitManual($review, $owner, 'Dank je!');
        $reply = ReviewReply::withoutGlobalScopes()->where('review_id', $review->id)->sole();

        try {
            $job = (new PublishReply($reply->id))->withFakeQueueInteractions();
            app()->call([$job, 'handle']);
            $this->fail('A retryable error must be re-thrown so the queue retries it.');
        } catch (GoogleApiException $e) {
            $this->assertTrue($e->isRetryable());
        }
        $this->assertSame(ReplyState::Publishing, $reply->fresh()->state, 'still in flight while retrying');

        // Retries exhausted
        (new PublishReply($reply->id))->failed(new \RuntimeException('Google unavailable'));
        $this->assertSame(ReplyState::Failed, $reply->fresh()->state);
        $this->assertSame(ReviewState::PublishFailed, $review->fresh()->workflow_state);

        // A person retries once Google is back.
        $this->actingAs($owner)->post(route('reviews.retry', $review->id));
        Queue::assertPushed(PublishReply::class, 2);
        app()->call([new PublishReply($reply->id), 'handle']);
        $this->assertSame(ReplyState::Published, $reply->fresh()->state);
    }

    public function test_reconnecting_google_resumes_disconnected_locations(): void
    {
        [$owner, $org] = $this->makeOrganization();
        $location = $this->makeLocation($org);
        $connection = GoogleConnection::withoutGlobalScopes()->first();
        Http::fake();

        app(GoogleLocationService::class)->disconnect($connection);
        $this->assertSame('disconnected', $location->fresh()->status);

        $this->actingAs($owner);
        app(GoogleLocationService::class)->storeConnection($org, $owner, [
            'access_token' => 'new', 'refresh_token' => 'r', 'expires_in' => 3600, 'scope' => 'https://www.googleapis.com/auth/business.manage', 'sub' => $connection->google_sub, 'email' => 'x@y.z',
        ]);

        $this->assertSame('active', $location->fresh()->status);
    }

    public function test_reviews_deleted_on_google_are_hidden_after_reconcile(): void
    {
        [, $org] = $this->makeOrganization();
        $location = $this->makeLocation($org, AutomationMode::Manual);
        $gone = $this->makeReview($location, 4);
        Http::fake(['mybusiness.googleapis.com/*' => Http::response(['reviews' => [$this->gbp('still-there', parent: $location->gbpLink->v4_parent)]])]);

        $this->runSync($location->gbpLink->id, 'reconcile');

        $this->assertNotNull($gone->fresh()->removed_at);
        $this->assertNull(Review::withoutGlobalScopes()->where('external_id', 'still-there')->first()->removed_at);
    }

    public function test_first_import_limits_ai_to_recent_reviews_and_does_not_use_the_allowance(): void
    {
        [, $org] = $this->makeOrganization();
        $link = $this->makeLocation($org)->gbpLink;
        $old = $this->gbp('old', updated: now()->subYear()->toIso8601String(), parent: $link->v4_parent);
        $old['createTime'] = now()->subYear()->toIso8601String();
        Http::fake(['mybusiness.googleapis.com/*' => Http::response(['reviews' => [$this->gbp('recent', parent: $link->v4_parent), $old]])]);

        $this->runSync($link->id, 'backfill');

        $this->assertSame(ReviewState::NoAction, Review::withoutGlobalScopes()->where('external_id', 'old')->first()->workflow_state);
        $this->assertNull(Review::withoutGlobalScopes()->where('external_id', 'old')->first()->analysis);
        $this->assertNotNull(Review::withoutGlobalScopes()->where('external_id', 'recent')->first()->analysis);
        $this->assertSame(0, (int) UsageRecord::withoutGlobalScopes()->where('metric', 'reviews_processed')->value('quantity'));
        $this->assertSame(1, (int) UsageRecord::withoutGlobalScopes()->where('metric', 'backfill_processed')->value('quantity'));
        $this->assertSame(2, AiGeneration::withoutGlobalScopes()->count(), 'analysis + draft for the one recent review');
    }

    public function test_pubsub_signing_key_rotation_is_handled(): void
    {
        config(['services.google.pubsub_audience' => 'aud', 'services.google.pubsub_service_account' => 'sa@x.iam.gserviceaccount.com']);
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $private);
        openssl_x509_export(openssl_csr_sign(openssl_csr_new(['commonName' => 't'], $key), null, $key, 1), $cert);

        Http::fake(['www.googleapis.com/oauth2/v1/certs' => Http::sequence()
            ->push(['old-kid' => 'old-cert'])
            ->push(['new-kid' => $cert])]);

        $b64 = fn ($v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        $make = function (string $kid) use ($b64, $private) {
            $h = $b64(json_encode(['alg' => 'RS256', 'kid' => $kid]));
            $p = $b64(json_encode(['iss' => 'accounts.google.com', 'aud' => 'aud', 'email' => 'sa@x.iam.gserviceaccount.com', 'email_verified' => true, 'exp' => time() + 60]));
            openssl_sign("{$h}.{$p}", $sig, $private, OPENSSL_ALGO_SHA256);

            return "Bearer {$h}.{$p}.".$b64($sig);
        };

        $verifier = app(PubSubTokenVerifier::class);
        $this->assertTrue($verifier->verify($make('new-kid')), 'unknown kid triggers a certificate refresh');
        $this->assertFalse($verifier->verify($make('unknown')));
    }

    public function test_failed_certificate_fetch_is_not_cached(): void
    {
        config(['services.google.pubsub_audience' => 'aud', 'services.google.pubsub_service_account' => 'sa@x']);
        Http::fake(['www.googleapis.com/oauth2/v1/certs' => Http::response('down', 500)]);

        app(PubSubTokenVerifier::class)->verify('Bearer a.b.c');

        $this->assertNull(cache('google-oidc-certs'));
    }
}
