<?php

namespace App\Services\Reviews;

use App\Enums\ReviewState;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Review;
use App\Notifications\NegativeReviewReceived;
use App\Services\Ai\GenerationRecorder;
use App\Services\Ai\LlmException;
use App\Services\Automation\AutomationEvaluator;
use App\Services\Billing\Entitlements;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Review lifecycle orchestration: analyse → draft → route (queue or auto-publish).
 */
class ReviewPipeline
{
    public function __construct(
        private ReviewAnalyzer $analyzer,
        private DraftGenerator $generator,
        private AutomationEvaluator $automation,
        private ReplyPublisher $publisher,
        private Entitlements $entitlements,
        private GenerationRecorder $usage,
    ) {}

    public function run(Review $review, bool $draft = true): void
    {
        $organization = Organization::findOrFail($review->organization_id);

        if (! $this->entitlements->canProcessWithAi($organization)) {
            // No plan or AI allowance used up: keep the review visible for a manual reply.
            $review->transitionTo($review->owner_reply_text ? ReviewState::NoAction : ReviewState::NeedsHumanWriting);

            return;
        }

        try {
            $analysis = $this->analyzer->analyze($review);
        } catch (Throwable $e) {
            $review->transitionTo(ReviewState::AnalysisFailed);
            throw $e;
        }

        $this->usage->meter($organization->id, 'reviews_processed', 1);
        $review->transitionTo(ReviewState::Analyzed);
        $review->refresh();

        $this->notifyIfNegative($review, $analysis->requires_human);

        if (! $draft || $review->owner_reply_origin !== 'none') {
            $review->transitionTo(ReviewState::NoAction);

            return;
        }

        $this->draft($review);
    }

    public function draft(Review $review, ?string $hint = null): void
    {
        $review->transitionTo(ReviewState::Drafting);
        $analysis = $review->analysis()->first();

        try {
            $drafts = $this->generator->generate($review, $analysis, $hint);
        } catch (LlmException $e) {
            // AI unavailable or declined: a person writes this one. Never block the review.
            report($e);
            $review->transitionTo(ReviewState::NeedsHumanWriting);

            return;
        }

        $best = $drafts->firstWhere('variant', 'standard') ?? $drafts->first();
        $passing = $drafts->where('guardrails_passed', true);

        if ($passing->isEmpty()) {
            $review->transitionTo(ReviewState::NeedsHumanWriting);

            return;
        }

        $best = $best->guardrails_passed ? $best : $passing->first();
        $best->setRelation('review', $review);

        // Regeneration requested by a person always goes back to that person.
        if ($hint === null && $analysis) {
            $decision = $this->automation->evaluate($review, $analysis, $best);

            if ($decision->eligible) {
                $this->publisher->schedule($best, $decision->publishAt);

                return;
            }
        }

        $review->transitionTo(ReviewState::PendingApproval);
    }

    private function notifyIfNegative(Review $review, bool $requiresHuman): void
    {
        if ($review->star_rating > 2 && ! $requiresHuman) {
            return;
        }

        $recipients = OrganizationMembership::withoutGlobalScopes()
            ->where('organization_id', $review->organization_id)
            ->with('user')
            ->get()
            ->filter(fn (OrganizationMembership $m) => $m->canApproveFor($review->location_id))
            ->map->user
            ->filter();

        Notification::send($recipients, new NegativeReviewReceived($review));
    }
}
