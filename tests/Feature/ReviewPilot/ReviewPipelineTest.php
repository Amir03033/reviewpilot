<?php

namespace Tests\Feature\ReviewPilot;

use App\Enums\AutomationMode;
use App\Enums\ReplyState;
use App\Enums\ReviewState;
use App\Jobs\ProcessReview;
use App\Models\ReviewReply;
use App\Notifications\NegativeReviewReceived;
use App\Services\Reviews\ReviewPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class ReviewPipelineTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['reviewpilot.ai.provider' => 'fake']);
        Notification::fake();
    }

    private function process($review): void
    {
        (new ProcessReview($review->id))->handle(app(ReviewPipeline::class));
        $review->refresh();
    }

    public function test_positive_low_risk_review_is_scheduled_in_smart_mode(): void
    {
        [, $org] = $this->makeOrganization();
        $review = $this->makeReview($this->makeLocation($org, AutomationMode::Smart), 5, 'Super lekker gegeten en vriendelijke bediening!');

        $this->process($review);

        $this->assertSame(ReviewState::ScheduledAuto, $review->workflow_state);
        $reply = ReviewReply::withoutGlobalScopes()->where('review_id', $review->id)->first();
        $this->assertSame(ReplyState::Scheduled, $reply->state);
        $this->assertSame('automation', $reply->published_via);
        $this->assertTrue($reply->scheduled_for->isAfter(now()->addMinutes(14)), 'hold window must apply');
        $this->assertSame(3, $review->drafts()->withoutGlobalScopes()->count());
        $this->assertNotEmpty($review->topicMentions()->withoutGlobalScopes()->get());
    }

    public function test_food_safety_review_always_needs_a_human_even_on_autopilot(): void
    {
        [, $org] = $this->makeOrganization();
        $review = $this->makeReview($this->makeLocation($org, AutomationMode::Autopilot), 4, 'Lekker, maar mijn vriend is ziek geworden van de kip.');

        $this->process($review);

        $this->assertSame(ReviewState::PendingApproval, $review->workflow_state);
        $this->assertContains('food_safety', $review->analysis->risk_flags);
        $this->assertNull(ReviewReply::withoutGlobalScopes()->where('review_id', $review->id)->first());
    }

    public function test_negative_reviews_wait_for_approval_and_notify_managers(): void
    {
        [$owner, $org] = $this->makeOrganization();
        $review = $this->makeReview($this->makeLocation($org), 1, 'We wachtten een uur op koud eten.');

        $this->process($review);

        $this->assertSame(ReviewState::PendingApproval, $review->workflow_state);
        Notification::assertSentTo($owner, NegativeReviewReceived::class);
    }

    public function test_manual_mode_never_schedules(): void
    {
        [, $org] = $this->makeOrganization();
        $review = $this->makeReview($this->makeLocation($org, AutomationMode::Manual), 5);

        $this->process($review);

        $this->assertSame(ReviewState::PendingApproval, $review->workflow_state);
    }

    public function test_kill_switch_blocks_automation(): void
    {
        [, $org] = $this->makeOrganization();
        $org->forceFill(['automation_kill_switch' => true])->save();
        $review = $this->makeReview($this->makeLocation($org), 5);

        $this->process($review);

        $this->assertSame(ReviewState::PendingApproval, $review->workflow_state);
    }

    public function test_without_plan_or_trial_reviews_go_to_manual_reply(): void
    {
        [, $org] = $this->makeOrganization();
        $org->forceFill(['trial_ends_at' => now()->subDay()])->save();
        $review = $this->makeReview($this->makeLocation($org), 5);

        $this->process($review);

        $this->assertSame(ReviewState::NeedsHumanWriting, $review->workflow_state);
        $this->assertNull($review->analysis);
    }

    public function test_reviews_already_answered_on_google_need_no_action(): void
    {
        [, $org] = $this->makeOrganization();
        $review = $this->makeReview($this->makeLocation($org), 5, 'Top!', [
            'owner_reply_text' => 'Dank je!',
            'owner_reply_origin' => 'external',
        ]);

        $this->process($review);

        $this->assertSame(ReviewState::NoAction, $review->workflow_state);
    }
}
