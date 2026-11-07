<?php

namespace Tests\Feature\ReviewPilot;

use App\Enums\AutomationMode;
use App\Enums\ReplyState;
use App\Enums\ReviewState;
use App\Jobs\ProcessReview;
use App\Models\ReviewReply;
use App\Services\Reviews\ReviewPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class PublishingTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_approving_a_draft_publishes_the_reply_on_google(): void
    {
        Http::fake([
            'mybusiness.googleapis.com/v4/*/reply' => Http::response(['comment' => 'Dank je wel, Jan!', 'updateTime' => now()->toIso8601String()]),
        ]);

        [$owner, $org] = $this->makeOrganization();
        $review = $this->makeReview($this->makeLocation($org, AutomationMode::Manual), 5);
        (new ProcessReview($review->id))->handle(app(ReviewPipeline::class));
        $draft = $review->drafts()->withoutGlobalScopes()->where('status', 'proposed')->first();

        $this->actingAs($owner)
            ->post(route('drafts.approve', $draft->id), ['text' => 'Dank je wel, Jan!'])
            ->assertRedirect();

        $review->refresh();
        $this->assertSame(ReviewState::Published, $review->workflow_state);
        $this->assertSame('reviewpilot', $review->owner_reply_origin);
        $reply = ReviewReply::withoutGlobalScopes()->where('review_id', $review->id)->first();
        $this->assertSame(ReplyState::Published, $reply->state);
        $this->assertSame('Dank je wel, Jan!', $reply->text);
        $this->assertSame('edit_approve', $draft->decisions()->withoutGlobalScopes()->first()->decision);

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_ends_with($request->url(), $review->external_name.'/reply')
            && $request['comment'] === 'Dank je wel, Jan!'
            && $request->hasHeader('Authorization', 'Bearer ya29.test'));

        $this->assertDatabaseHas('audit_logs', ['action' => 'reply.published', 'subject_id' => $review->id]);
    }

    public function test_a_policy_rejection_from_google_marks_the_reply_failed(): void
    {
        Http::fake([
            'mybusiness.googleapis.com/*' => Http::response(['error' => ['code' => 400, 'message' => 'Reply violates policy', 'status' => 'INVALID_ARGUMENT']], 400),
        ]);

        [$owner, $org] = $this->makeOrganization();
        $review = $this->makeReview($this->makeLocation($org, AutomationMode::Manual), 5);

        $this->actingAs($owner)->post(route('reviews.reply', $review->id), ['text' => 'Thanks!']);

        $review->refresh();
        $this->assertSame(ReviewState::PublishFailed, $review->workflow_state);
        $reply = ReviewReply::withoutGlobalScopes()->where('review_id', $review->id)->first();
        $this->assertSame(ReplyState::Failed, $reply->state);
        $this->assertSame('INVALID_ARGUMENT', $reply->google_policy_rejection['error']['status']);
    }

    public function test_scheduled_replies_publish_after_hold_window_unless_paused(): void
    {
        Http::fake(['mybusiness.googleapis.com/*' => Http::response(['comment' => 'ok'])]);

        [, $org] = $this->makeOrganization();
        $review = $this->makeReview($this->makeLocation($org, AutomationMode::Smart), 5, 'Heerlijk gegeten, top bediening!');
        (new ProcessReview($review->id))->handle(app(ReviewPipeline::class));
        $reply = ReviewReply::withoutGlobalScopes()->where('review_id', $review->id)->firstOrFail();

        Artisan::call('reviewpilot:publish-due');
        $this->assertSame(ReplyState::Scheduled, $reply->fresh()->state, 'not due yet');

        $this->travelTo($reply->scheduled_for->addMinute());
        Artisan::call('reviewpilot:publish-due');

        $this->assertSame(ReplyState::Published, $reply->fresh()->state);
        $this->assertSame(ReviewState::Published, $review->fresh()->workflow_state);
    }

    public function test_kill_switch_returns_scheduled_replies_to_the_queue(): void
    {
        Http::fake();

        [, $org] = $this->makeOrganization();
        $review = $this->makeReview($this->makeLocation($org, AutomationMode::Smart), 5, 'Heerlijk gegeten, top bediening!');
        (new ProcessReview($review->id))->handle(app(ReviewPipeline::class));
        $reply = ReviewReply::withoutGlobalScopes()->where('review_id', $review->id)->firstOrFail();

        $org->forceFill(['automation_kill_switch' => true])->save();
        $this->travelTo($reply->scheduled_for->addMinute());
        Artisan::call('reviewpilot:publish-due');

        $this->assertSame(ReplyState::Cancelled, $reply->fresh()->state);
        $this->assertSame(ReviewState::PendingApproval, $review->fresh()->workflow_state);
        Http::assertNothingSent();
    }

    public function test_cancelling_a_scheduled_reply_puts_it_back_in_the_queue(): void
    {
        [$owner, $org] = $this->makeOrganization();
        $review = $this->makeReview($this->makeLocation($org, AutomationMode::Smart), 5, 'Heerlijk gegeten, top bediening!');
        (new ProcessReview($review->id))->handle(app(ReviewPipeline::class));

        $this->actingAs($owner)->post(route('reviews.cancel-scheduled', $review->id))->assertRedirect();

        $this->assertSame(ReviewState::PendingApproval, $review->fresh()->workflow_state);
    }
}
