<?php

namespace App\Services\Reviews;

use App\Enums\DraftStatus;
use App\Enums\ReplyState;
use App\Enums\ReviewState;
use App\Jobs\PublishReply;
use App\Models\ApprovalDecision;
use App\Models\ResponseDraft;
use App\Models\Review;
use App\Models\ReviewReply;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Human and automated decisions on drafts, and the hand-off to publishing.
 */
class ReplyPublisher
{
    public function __construct(private AuditLogger $audit) {}

    /** Approve a draft (optionally edited) and publish it now. */
    public function approve(ResponseDraft $draft, User $user, ?string $editedText = null): ReviewReply
    {
        $text = trim($editedText ?? $draft->text);
        if ($text === '') {
            throw new InvalidArgumentException('Reply text cannot be empty.');
        }

        $reply = DB::transaction(function () use ($draft, $user, $text) {
            $edited = $text !== trim((string) $draft->original_text);

            $draft->forceFill([
                'text' => $text,
                'status' => DraftStatus::Approved,
                'edit_distance_ratio' => $draft->original_text ? $this->editRatio((string) $draft->original_text, $text) : null,
            ])->save();

            $this->supersedeOthers($draft);

            ApprovalDecision::withoutGlobalScopes()->create([
                'organization_id' => $draft->organization_id,
                'draft_id' => $draft->id,
                'decided_by' => $user->id,
                'decision' => $edited ? 'edit_approve' : 'approve',
            ]);

            $reply = $this->upsertReply($draft, $text, ReplyState::Publishing, 'user');
            $draft->review->transitionTo(ReviewState::Publishing);

            $this->audit->log('reply.approved', $draft->review, ['draft_id' => $draft->id, 'edited' => $edited]);

            return $reply;
        });

        PublishReply::dispatch($reply->id);

        return $reply;
    }

    /** A reply written entirely by a person. */
    public function submitManual(Review $review, User $user, string $text): ReviewReply
    {
        $draft = ResponseDraft::withoutGlobalScopes()->create([
            'organization_id' => $review->organization_id,
            'location_id' => $review->location_id,
            'review_id' => $review->id,
            'variant' => 'manual',
            'text' => $text,
            'original_text' => null,
            'status' => DraftStatus::Proposed,
            'guardrails_passed' => true,
            'created_by' => $user->id,
        ]);
        $draft->setRelation('review', $review);

        return $this->approve($draft, $user, $text);
    }

    public function reject(Review $review, User $user, ?string $note = null): void
    {
        DB::transaction(function () use ($review, $user, $note) {
            $drafts = ResponseDraft::withoutGlobalScopes()->where('review_id', $review->id)->where('status', DraftStatus::Proposed->value)->get();

            foreach ($drafts as $draft) {
                $draft->forceFill(['status' => DraftStatus::Rejected])->save();
                ApprovalDecision::withoutGlobalScopes()->create([
                    'organization_id' => $review->organization_id,
                    'draft_id' => $draft->id,
                    'decided_by' => $user->id,
                    'decision' => 'reject',
                    'note' => $note,
                ]);
            }

            $review->transitionTo(ReviewState::Rejected);
            $this->audit->log('review.rejected', $review, ['note' => $note]);
        });
    }

    /** Automation: schedule the draft for publication after the hold window. */
    public function schedule(ResponseDraft $draft, CarbonInterface $publishAt): ReviewReply
    {
        return DB::transaction(function () use ($draft, $publishAt) {
            $draft->forceFill(['status' => DraftStatus::Approved])->save();
            $this->supersedeOthers($draft);

            ApprovalDecision::withoutGlobalScopes()->create([
                'organization_id' => $draft->organization_id,
                'draft_id' => $draft->id,
                'decision' => 'auto_approve',
                'policy_id' => $draft->review->location?->automationPolicy?->id,
            ]);

            $reply = $this->upsertReply($draft, $draft->text, ReplyState::Scheduled, 'automation', $publishAt);
            $draft->review->transitionTo(ReviewState::ScheduledAuto);

            $this->audit->log('reply.scheduled', $draft->review, ['publish_at' => $publishAt->toIso8601String()], actorType: 'automation');

            return $reply;
        });
    }

    /** Cancel a scheduled auto-reply and send the review back to the approval queue. */
    public function cancelScheduled(Review $review, User $user): void
    {
        DB::transaction(function () use ($review, $user) {
            $reply = ReviewReply::withoutGlobalScopes()->where('review_id', $review->id)->first();

            if (! $reply || $reply->state !== ReplyState::Scheduled) {
                throw new InvalidArgumentException('There is no scheduled reply to cancel.');
            }

            $reply->forceFill(['state' => ReplyState::Cancelled])->save();

            if ($reply->draft) {
                $reply->draft->forceFill(['status' => DraftStatus::Proposed])->save();
            }

            $review->transitionTo(ReviewState::PendingApproval);
            $this->audit->log('reply.schedule_cancelled', $review, [], actorId: (string) $user->id);
        });
    }

    public function retry(Review $review): void
    {
        $reply = ReviewReply::withoutGlobalScopes()->where('review_id', $review->id)->firstOrFail();

        if ($reply->state !== ReplyState::Failed) {
            throw new InvalidArgumentException('Only failed replies can be retried.');
        }

        $reply->forceFill(['state' => ReplyState::Publishing, 'last_error' => null])->save();
        $review->transitionTo(ReviewState::Publishing);

        PublishReply::dispatch($reply->id);
    }

    private function upsertReply(ResponseDraft $draft, string $text, ReplyState $state, string $via, ?CarbonInterface $scheduledFor = null): ReviewReply
    {
        $reply = ReviewReply::withoutGlobalScopes()->firstOrNew(['review_id' => $draft->review_id]);

        if ($reply->exists && $reply->state === ReplyState::Publishing) {
            throw new InvalidArgumentException('This reply is already being published.');
        }

        $reply->forceFill([
            'organization_id' => $draft->organization_id,
            'draft_id' => $draft->id,
            'text' => $text,
            'state' => $state,
            'scheduled_for' => $scheduledFor,
            'published_via' => $via,
            'last_error' => null,
            'google_policy_rejection' => null,
        ])->save();

        return $reply;
    }

    private function supersedeOthers(ResponseDraft $draft): void
    {
        ResponseDraft::withoutGlobalScopes()
            ->where('review_id', $draft->review_id)
            ->where('id', '!=', $draft->id)
            ->where('status', DraftStatus::Proposed->value)
            ->update(['status' => DraftStatus::Superseded->value]);
    }

    /** Normalised Levenshtein-style distance (0 = identical, 1 = fully rewritten). */
    private function editRatio(string $original, string $final): float
    {
        if ($original === $final) {
            return 0.0;
        }

        similar_text($original, $final, $percent);

        return round(1 - $percent / 100, 4);
    }
}
