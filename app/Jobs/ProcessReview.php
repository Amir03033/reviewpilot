<?php

namespace App\Jobs;

use App\Models\Review;
use App\Services\Reviews\ReviewPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/** Analyse a review, draft replies and route them (queue or auto-publish). */
class ProcessReview implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public function __construct(public string $reviewId, public bool $draft = true, public ?string $hint = null, public bool $backfill = false)
    {
        $this->onQueue('ai');
        $this->afterCommit();
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->reviewId))->releaseAfter(30)->expireAfter(300)];
    }

    public function handle(ReviewPipeline $pipeline): void
    {
        $review = Review::withoutGlobalScopes()->find($this->reviewId);

        if (! $review || $review->removed_at) {
            return;
        }

        if ($this->hint !== null) {
            $pipeline->draft($review, $this->hint);

            return;
        }

        $pipeline->run($review, $this->draft, $this->backfill);
    }
}
