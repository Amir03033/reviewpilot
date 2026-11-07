<?php

namespace App\Services\Reviews;

use App\Enums\DraftStatus;
use App\Enums\ReplyState;
use App\Enums\ReviewState;
use App\Jobs\ProcessReview;
use App\Models\Location;
use App\Models\ResponseDraft;
use App\Models\Review;
use App\Models\ReviewReply;
use App\Models\ReviewRevision;
use App\Services\Google\GbpReview;
use Illuminate\Support\Facades\DB;

/**
 * Upserts reviews from Google and decides what needs (re)processing.
 */
class ReviewIngestor
{
    /**
     * @return array{review: Review, created: bool, content_changed: bool}
     */
    public function ingest(Location $location, GbpReview $data, bool $isBackfill = false): array
    {
        return DB::transaction(function () use ($location, $data, $isBackfill) {
            $review = Review::withoutGlobalScopes()
                ->where('location_id', $location->id)
                ->where('source', 'google')
                ->where('external_id', $data->reviewId)
                ->lockForUpdate()
                ->first();

            if (! $review) {
                $review = $this->create($location, $data, $isBackfill);

                return ['review' => $review, 'created' => true, 'content_changed' => true];
            }

            $contentChanged = $review->star_rating !== $data->starRating
                || trim((string) $review->comment) !== trim((string) $data->comment);

            if ($contentChanged) {
                ReviewRevision::withoutGlobalScopes()->create([
                    'organization_id' => $review->organization_id,
                    'review_id' => $review->id,
                    'star_rating' => $review->star_rating,
                    'comment' => $review->comment,
                    'updated_at_source' => $review->updated_at_source,
                ]);
            }

            $review->forceFill([
                'reviewer_display_name' => $data->reviewerName,
                'reviewer_is_anonymous' => $data->reviewerAnonymous,
                'star_rating' => $data->starRating,
                'comment' => $data->comment,
                'updated_at_source' => $data->updateTime,
                'removed_at' => null,
            ]);

            $this->syncOwnerReply($review, $data);

            if ($contentChanged) {
                $this->markChanged($review);
            }

            $review->save();

            if ($contentChanged && $review->workflow_state !== ReviewState::NoAction) {
                ProcessReview::dispatch($review->id);
            }

            return ['review' => $review, 'created' => false, 'content_changed' => $contentChanged];
        });
    }

    private function create(Location $location, GbpReview $data, bool $isBackfill): Review
    {
        $alreadyReplied = filled($data->replyComment);
        $tooOld = $isBackfill && $data->createTime->lt(now()->subDays((int) config('reviewpilot.sync.backfill_draft_days', 30)));

        $review = Review::withoutGlobalScopes()->create([
            'organization_id' => $location->organization_id,
            'location_id' => $location->id,
            'source' => 'google',
            'external_id' => $data->reviewId,
            'external_name' => $data->name,
            'reviewer_display_name' => $data->reviewerName,
            'reviewer_is_anonymous' => $data->reviewerAnonymous,
            'star_rating' => $data->starRating,
            'comment' => $data->comment,
            'created_at_source' => $data->createTime,
            'updated_at_source' => $data->updateTime,
            'owner_reply_text' => $data->replyComment,
            'owner_reply_updated_at' => $data->replyUpdateTime,
            'owner_reply_origin' => $alreadyReplied ? 'external' : 'none',
            'workflow_state' => ReviewState::Received,
        ]);

        // Always analyse (for insights); only draft for recent, unanswered reviews.
        ProcessReview::dispatch($review->id, draft: ! $alreadyReplied && ! $tooOld);

        return $review;
    }

    /** Detect replies made directly in Google (outside ReviewPilot). */
    private function syncOwnerReply(Review $review, GbpReview $data): void
    {
        $ours = ReviewReply::withoutGlobalScopes()->where('review_id', $review->id)->first();
        $googleText = $data->replyComment;

        $review->owner_reply_text = $googleText;
        $review->owner_reply_updated_at = $data->replyUpdateTime;

        if ($googleText === null) {
            $review->owner_reply_origin = 'none';

            return;
        }

        $isOurs = $ours && $ours->state === ReplyState::Published && trim($ours->text) === trim($googleText);
        $review->owner_reply_origin = $isOurs ? 'reviewpilot' : 'external';

        if (! $isOurs && in_array($review->workflow_state, [ReviewState::PendingApproval, ReviewState::NeedsHumanWriting, ReviewState::Received, ReviewState::Analyzed, ReviewState::Drafting], true)) {
            ResponseDraft::withoutGlobalScopes()->where('review_id', $review->id)->where('status', DraftStatus::Proposed->value)
                ->update(['status' => DraftStatus::Superseded->value]);
            $review->workflow_state = ReviewState::NoAction;
        }
    }

    private function markChanged(Review $review): void
    {
        ResponseDraft::withoutGlobalScopes()->where('review_id', $review->id)->where('status', DraftStatus::Proposed->value)
            ->update(['status' => DraftStatus::Stale->value]);

        $scheduled = ReviewReply::withoutGlobalScopes()->where('review_id', $review->id)->where('state', ReplyState::Scheduled->value)->first();
        $scheduled?->forceFill(['state' => ReplyState::Cancelled])->save();

        $review->workflow_state = match ($review->workflow_state) {
            ReviewState::Published, ReviewState::NoAction => $review->owner_reply_origin === 'none' ? ReviewState::Received : ReviewState::Stale,
            default => ReviewState::Received,
        };
    }
}
