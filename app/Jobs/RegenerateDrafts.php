<?php

namespace App\Jobs;

use App\Enums\ReviewState;
use App\Models\Review;
use App\Services\Reviews\ReviewPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** New draft variants requested by a person (optionally with guidance). Always returns to the queue. */
class RegenerateDrafts implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public string $reviewId, public string $hint = '')
    {
        $this->onQueue('ai');
        $this->afterCommit();
    }

    public function handle(ReviewPipeline $pipeline): void
    {
        $review = Review::withoutGlobalScopes()->find($this->reviewId);

        if (! $review) {
            return;
        }

        try {
            $pipeline->draft($review, $this->hint === '' ? 'Write fresh alternatives.' : $this->hint);
        } catch (\Throwable $e) {
            $review->transitionTo(ReviewState::NeedsHumanWriting);
            throw $e;
        }
    }
}
