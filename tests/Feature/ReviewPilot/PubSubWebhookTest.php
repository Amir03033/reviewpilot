<?php

namespace Tests\Feature\ReviewPilot;

use App\Models\InboundEvent;
use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class PubSubWebhookTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private ?string $privateKey = null;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config([
            'services.google.pubsub_audience' => 'https://app.test/webhooks/google/pubsub',
            'services.google.pubsub_service_account' => 'pubsub-push@project.iam.gserviceaccount.com',
        ]);

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $this->privateKey);
        $csr = openssl_csr_new(['commonName' => 'test'], $key);
        openssl_x509_export(openssl_csr_sign($csr, null, $key, 1), $cert);

        Http::fake(['www.googleapis.com/oauth2/v1/certs' => Http::response(['kid-1' => $cert])]);
    }

    private function jwt(array $claims): string
    {
        $b64 = fn ($v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        $head = $b64(json_encode(['alg' => 'RS256', 'kid' => 'kid-1', 'typ' => 'JWT']));
        $body = $b64(json_encode($claims + [
            'iss' => 'https://accounts.google.com',
            'aud' => 'https://app.test/webhooks/google/pubsub',
            'email' => 'pubsub-push@project.iam.gserviceaccount.com',
            'email_verified' => true,
            'exp' => time() + 600,
        ]));
        openssl_sign("{$head}.{$body}", $sig, $this->privateKey, OPENSSL_ALGO_SHA256);

        return "{$head}.{$body}.".$b64($sig);
    }

    private function payload(string $locationName, string $reviewName, string $messageId = 'm-1'): array
    {
        return ['message' => [
            'messageId' => $messageId,
            'data' => base64_encode(json_encode(['type' => 'NEW_REVIEW', 'review' => $reviewName, 'location' => $locationName])),
        ], 'subscription' => 'projects/p/subscriptions/s'];
    }

    public function test_unauthenticated_pushes_are_rejected(): void
    {
        $this->postJson('/webhooks/google/pubsub', $this->payload('a', 'b'))->assertUnauthorized();
        $this->postJson('/webhooks/google/pubsub', $this->payload('a', 'b'), ['Authorization' => 'Bearer '.$this->jwt(['aud' => 'wrong'])])->assertUnauthorized();
        $this->assertSame(0, InboundEvent::count());
    }

    public function test_valid_notification_fetches_and_ingests_the_review_once(): void
    {
        [, $org] = $this->makeOrganization();
        $location = $this->makeLocation($org);
        $link = $location->gbpLink()->withoutGlobalScopes()->first();
        $reviewName = "{$link->v4_parent}/reviews/new1";

        Http::fake(['mybusiness.googleapis.com/*' => Http::response([
            'name' => $reviewName, 'reviewId' => 'new1', 'reviewer' => ['displayName' => 'Iris'], 'starRating' => 'FOUR',
            'comment' => 'Fijne avond gehad', 'createTime' => now()->toIso8601String(), 'updateTime' => now()->toIso8601String(),
        ])]);

        $headers = ['Authorization' => 'Bearer '.$this->jwt([])];
        $this->postJson('/webhooks/google/pubsub', $this->payload($link->v4_parent, $reviewName), $headers)->assertNoContent();
        $this->postJson('/webhooks/google/pubsub', $this->payload($link->v4_parent, $reviewName), $headers)->assertNoContent(); // duplicate delivery

        $this->assertSame(1, InboundEvent::count());
        $this->assertNotNull(InboundEvent::first()->processed_at);
        $this->assertSame(1, Review::withoutGlobalScopes()->where('external_id', 'new1')->count());
    }
}
