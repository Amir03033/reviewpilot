<?php

namespace App\Jobs;

use App\Enums\ReplyState;
use App\Enums\ReviewState;
use App\Models\GbpLocationLink;
use App\Models\Organization;
use App\Models\PublicationAttempt;
use App\Models\Review;
use App\Models\ReviewReply;
use App\Services\Audit\AuditLogger;
use App\Services\Google\GbpClient;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleRateLimited;
use App\Services\Google\GoogleReauthRequired;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/** Publish (upsert) the owner reply on Google. Idempotent per reply. */
class PublishReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public function __construct(public string $replyId)
    {
        $this->onQueue('realtime');
        $this->afterCommit();
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('publish:'.$this->replyId))->expireAfter(120)];
    }

    public function backoff(): array
    {
        return [10, 30, 60, 120, 300, 600, 1200];
    }

    public function handle(GbpClient $client, AuditLogger $audit): void
    {
        $reply = ReviewReply::withoutGlobalScopes()->find($this->replyId);

        if (! $reply || $reply->state !== ReplyState::Publishing) {
            return; // cancelled, already published, or superseded
        }

        $review = Review::withoutGlobalScopes()->findOrFail($reply->review_id);
        $organization = Organization::findOrFail($review->organization_id);

        // Automated replies respect the kill switch up to the very last moment.
        if ($reply->published_via === 'automation' && $organization->automation_kill_switch) {
            $reply->forceFill(['state' => ReplyState::Cancelled])->save();
            $review->transitionTo(ReviewState::PendingApproval);

            return;
        }

        $link = GbpLocationLink::withoutGlobalScopes()->with('account.connection')->where('location_id', $review->location_id)->first();

        if (! $link) {
            $this->fail($reply, $review, 'This location is not connected to Google.', null);

            return;
        }

        $attempt = PublicationAttempt::withoutGlobalScopes()->create([
            'organization_id' => $reply->organization_id,
            'reply_id' => $reply->id,
            'attempt_no' => $reply->attempts + 1,
        ]);
        $reply->increment('attempts');

        try {
            $result = $client->updateReply($link->account->connection, $review->external_name, $reply->text);
        } catch (GoogleRateLimited $e) {
            $attempt->forceFill(['error_code' => 'rate_limited', 'finished_at' => now()])->save();
            $this->release($e->retryAfterSeconds + 1);

            return;
        } catch (GoogleReauthRequired $e) {
            $attempt->forceFill(['error_code' => 'reauth_required', 'finished_at' => now()])->save();
            $this->fail($reply, $review, 'Google connection needs to be reconnected.', null);

            return;
        } catch (GoogleApiException $e) {
            $attempt->forceFill([
                'http_status' => $e->status ?: null,
                'error_code' => $e->errorStatus(),
                'response_excerpt' => mb_substr((string) json_encode($e->body), 0, 2000),
                'finished_at' => now(),
            ])->save();

            if ($e->isRetryable() && $this->attempts() < $this->tries) {
                throw $e; // retried with backoff
            }

            $this->fail($reply, $review, $e->getMessage(), $e->status === 400 ? $e->body : null);

            return;
        }

        $attempt->forceFill(['http_status' => 200, 'finished_at' => now()])->save();

        $reply->forceFill([
            'state' => ReplyState::Published,
            'published_at' => now(),
            'last_error' => null,
        ])->save();

        $review->forceFill([
            'owner_reply_text' => $result['comment'] ?? $reply->text,
            'owner_reply_updated_at' => now(),
            'owner_reply_origin' => 'reviewpilot',
            'workflow_state' => ReviewState::Published,
        ])->save();

        $audit->log('reply.published', $review, ['via' => $reply->published_via], actorType: $reply->published_via === 'automation' ? 'automation' : 'system');
    }

    /** Called by the queue when all retries are exhausted. */
    public function failed(?\Throwable $exception): void
    {
        $reply = ReviewReply::withoutGlobalScopes()->find($this->replyId);
        $review = $reply ? Review::withoutGlobalScopes()->find($reply->review_id) : null;

        if ($reply && $review && $reply->state === ReplyState::Publishing) {
            $this->fail($reply, $review, $exception?->getMessage() ?? 'Publishing failed.', null);
        }
    }

    private function fail(ReviewReply $reply, Review $review, string $message, ?array $policyRejection): void
    {
        $reply->forceFill([
            'state' => ReplyState::Failed,
            'last_error' => $message,
            'google_policy_rejection' => $policyRejection,
        ])->save();

        $review->transitionTo(ReviewState::PublishFailed);
    }
}
