<?php

namespace Tests\Feature\Production;

use App\Enums\AutomationMode;
use App\Enums\ReplyState;
use App\Enums\ReviewState;
use App\Jobs\ProcessReview;
use App\Jobs\PublishReply;
use App\Models\ReviewAnalysis;
use App\Models\ReviewReply;
use App\Services\Google\GbpReview;
use App\Services\Reviews\ReviewIngestor;
use App\Services\Reviews\ReviewPipeline;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class AutomationSafetyTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Http::fake(['mybusiness.googleapis.com/*' => Http::response(['comment' => 'ok'])]);
    }

    private function autoReview($org, ?callable $tweakPolicy = null)
    {
        $location = $this->makeLocation($org, AutomationMode::Smart);
        if ($tweakPolicy) {
            $tweakPolicy($location->policy());
        }
        $review = $this->makeReview($location, 5, 'Heerlijk gegeten, top bediening!');
        (new ProcessReview($review->id))->handle(app(ReviewPipeline::class));

        return [$review->refresh(), $location];
    }

    public function test_two_hour_hold_window_is_applied(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00', 'Europe/Amsterdam'));
        [, $org] = $this->makeOrganization();
        [$review] = $this->autoReview($org);

        $reply = ReviewReply::withoutGlobalScopes()->where('review_id', $review->id)->sole();
        $this->assertEquals(now()->addMinutes(120)->startOfMinute(), $reply->scheduled_for->startOfMinute());

        $this->travelTo(now()->addMinutes(119));
        Artisan::call('reviewpilot:publish-due');
        $this->assertSame(ReplyState::Scheduled, $reply->fresh()->state);
        Http::assertNothingSent();

        $this->travelTo(now()->addMinutes(2));
        Artisan::call('reviewpilot:publish-due');
        $this->assertSame(ReplyState::Published, $reply->fresh()->state);
    }

    public function test_late_night_reviews_wait_for_business_hours(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 23:30', 'Europe/Amsterdam'));
        [, $org] = $this->makeOrganization();
        [$review] = $this->autoReview($org);

        $at = ReviewReply::withoutGlobalScopes()->where('review_id', $review->id)->sole()->scheduled_for->setTimezone('Europe/Amsterdam');
        $this->assertSame('2026-11-11 08:00', $at->format('Y-m-d H:i'));
    }

    public function test_hold_window_cannot_be_configured_below_fifteen_minutes(): void
    {
        [$owner, $org] = $this->makeOrganization();
        $location = $this->makeLocation($org);

        $this->actingAs($owner)->put(route('locations.policy', $location->id), [
            'min_rating' => 4, 'max_risk_score' => 20, 'hold_minutes' => 1, 'daily_cap' => 5, 'business_hours_only' => false,
        ])->assertSessionHasErrors('hold_minutes');
    }

    public function test_daily_cap_is_respected(): void
    {
        [, $org] = $this->makeOrganization();
        $location = $this->makeLocation($org, AutomationMode::Smart);
        $location->policy()->forceFill(['daily_cap' => 2])->save();

        $states = [];
        foreach (range(1, 3) as $i) {
            $review = $this->makeReview($location, 5, 'Heerlijk gegeten, top bediening!');
            (new ProcessReview($review->id))->handle(app(ReviewPipeline::class));
            $states[] = $review->fresh()->workflow_state;
        }

        $this->assertSame([ReviewState::ScheduledAuto, ReviewState::ScheduledAuto, ReviewState::PendingApproval], $states);
    }

    public function test_platform_wide_pause_stops_scheduling_and_publishing(): void
    {
        [, $org] = $this->makeOrganization();
        [$review] = $this->autoReview($org);
        $reply = ReviewReply::withoutGlobalScopes()->where('review_id', $review->id)->sole();

        Artisan::call('reviewpilot:automation', ['action' => 'pause']);

        // Already scheduled: goes back to a person.
        $this->travelTo($reply->scheduled_for->addMinute());
        Artisan::call('reviewpilot:publish-due');
        $this->assertSame(ReplyState::Cancelled, $reply->fresh()->state);
        $this->assertSame(ReviewState::PendingApproval, $review->fresh()->workflow_state);

        // New reviews are not scheduled at all.
        [$second] = $this->autoReview($org);
        $this->assertSame(ReviewState::PendingApproval, $second->workflow_state);
        Http::assertNothingSent();

        Artisan::call('reviewpilot:automation', ['action' => 'resume']);
        [$third] = $this->autoReview($org);
        $this->assertSame(ReviewState::ScheduledAuto, $third->workflow_state);
    }

    public function test_organization_pause_also_stops_a_job_that_is_already_queued(): void
    {
        [, $org] = $this->makeOrganization();
        [$review] = $this->autoReview($org);
        $reply = ReviewReply::withoutGlobalScopes()->where('review_id', $review->id)->sole();
        $reply->forceFill(['state' => ReplyState::Publishing])->save(); // job was already dispatched

        $org->forceFill(['automation_kill_switch' => true])->save();
        app()->call([new PublishReply($reply->id), 'handle']);

        $this->assertSame(ReplyState::Cancelled, $reply->fresh()->state);
        Http::assertNothingSent();
    }

    public function test_review_that_became_sensitive_after_scheduling_is_not_published(): void
    {
        [, $org] = $this->makeOrganization();
        [$review] = $this->autoReview($org);
        $reply = ReviewReply::withoutGlobalScopes()->where('review_id', $review->id)->sole();

        ReviewAnalysis::withoutGlobalScopes()->where('review_id', $review->id)->update(['risk_flags' => json_encode(['food_safety']), 'requires_human' => true]);

        $this->travelTo($reply->scheduled_for->addMinute());
        Artisan::call('reviewpilot:publish-due');

        $this->assertSame(ReplyState::Cancelled, $reply->fresh()->state);
        $this->assertSame(ReviewState::PendingApproval, $review->fresh()->workflow_state);
        $this->assertDatabaseHas('audit_logs', ['action' => 'reply.auto_publish_blocked', 'subject_id' => $review->id]);
        Http::assertNothingSent();
    }

    public function test_customer_editing_the_review_cancels_the_scheduled_reply(): void
    {
        [, $org] = $this->makeOrganization();
        [$review, $location] = $this->autoReview($org);
        $reply = ReviewReply::withoutGlobalScopes()->where('review_id', $review->id)->sole();

        app(ReviewIngestor::class)->ingest($location, GbpReview::fromApi([
            'name' => $review->external_name, 'reviewId' => $review->external_id, 'reviewer' => ['displayName' => 'Jan'],
            'starRating' => 'ONE', 'comment' => 'Toch slecht, ik werd ziek.', 'createTime' => now()->subHour()->toIso8601String(), 'updateTime' => now()->toIso8601String(),
        ]));

        $this->assertSame(ReplyState::Cancelled, $reply->fresh()->state);
        $this->assertNotSame(ReviewState::ScheduledAuto, $review->fresh()->workflow_state);
    }

    public function test_publishing_twice_sends_one_request_and_double_approval_is_refused(): void
    {
        [$owner, $org] = $this->makeOrganization();
        $review = $this->makeReview($this->makeLocation($org, AutomationMode::Manual), 5);
        (new ProcessReview($review->id))->handle(app(ReviewPipeline::class));
        $draft = $review->drafts()->withoutGlobalScopes()->where('status', 'proposed')->first();

        $this->actingAs($owner)->post(route('drafts.approve', $draft->id))->assertRedirect();
        $this->actingAs($owner)->post(route('drafts.approve', $draft->id))->assertRedirect(); // double click

        $reply = ReviewReply::withoutGlobalScopes()->where('review_id', $review->id)->sole();
        app()->call([new PublishReply($reply->id), 'handle']); // a duplicated queue job

        Http::assertSentCount(1);
        $this->assertSame(1, ReviewReply::withoutGlobalScopes()->where('review_id', $review->id)->count());
    }

    public function test_imported_history_is_never_auto_published(): void
    {
        [, $org] = $this->makeOrganization();
        $location = $this->makeLocation($org, AutomationMode::Autopilot);
        $review = $this->makeReview($location, 5, 'Heerlijk gegeten, top bediening!');

        (new ProcessReview($review->id, backfill: true))->handle(app(ReviewPipeline::class));

        $this->assertSame(ReviewState::PendingApproval, $review->fresh()->workflow_state);
    }

    public function test_three_star_reviews_need_autopilot_and_never_go_out_in_smart_mode(): void
    {
        [, $org] = $this->makeOrganization();
        $location = $this->makeLocation($org, AutomationMode::Smart);
        $location->policy()->forceFill(['min_rating' => 3])->save(); // smart ignores < 4
        $review = $this->makeReview($location, 3, 'Prima eten, gezellige sfeer.');

        (new ProcessReview($review->id))->handle(app(ReviewPipeline::class));

        $this->assertSame(ReviewState::PendingApproval, $review->fresh()->workflow_state);
    }
}
