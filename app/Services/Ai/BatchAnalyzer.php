<?php

namespace App\Services\Ai;

use App\Jobs\ProcessReview;
use App\Models\AiBatch;
use App\Models\AiBatchItem;
use App\Models\Review;
use App\Services\Reviews\ReviewAnalyzer;
use App\Services\Reviews\ReviewPipeline;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * First import of a location: reviews older than the draft window only need an analysis
 * (for insights), not a reply. Those go through Anthropic's Message Batches API, which
 * costs half of a normal request. Results usually arrive within an hour.
 *
 * Anything the batch cannot handle (errors, refusals, invalid output, expiry) falls back to
 * the normal one-by-one ProcessReview job, so no review is ever left unprocessed.
 */
class BatchAnalyzer
{
    /** Wait this long after the last queued review so a whole import goes in one batch. */
    private const SETTLE_SECONDS = 60;

    private const MAX_PER_BATCH = 5000;

    public function __construct(
        private ReviewAnalyzer $analyzer,
        private ReviewPipeline $pipeline,
        private GenerationRecorder $recorder,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('reviewpilot.ai.batch_backfill', true)
            && in_array(config('reviewpilot.ai.provider'), ['anthropic', 'claude'], true)
            && filled(config('services.anthropic.key'));
    }

    public function queue(Review $review): void
    {
        AiBatchItem::query()->firstOrCreate(
            ['review_id' => $review->id],
            ['organization_id' => $review->organization_id, 'status' => 'pending'],
        );
    }

    /** Submit waiting reviews as one batch. Returns the number submitted. */
    public function submit(): int
    {
        $latest = AiBatchItem::query()->where('status', 'pending')->max('created_at');
        if ($latest === null || now()->diffInSeconds($latest, true) < self::SETTLE_SECONDS) {
            return 0;
        }

        $items = AiBatchItem::query()->where('status', 'pending')->oldest()->limit(self::MAX_PER_BATCH)->get();
        $reviews = Review::withoutGlobalScopes()->whereIn('id', $items->pluck('review_id'))->get()->keyBy('id');

        $requests = [];
        foreach ($items as $item) {
            $review = $reviews[$item->review_id] ?? null;
            if (! $review || ! $review->hasText()) {
                $this->fallback($item);

                continue;
            }

            $request = $this->analyzer->request($review);
            $requests[] = [
                'customID' => $review->id,
                'params' => [
                    'model' => ClaudeClient::model($request),
                    'maxTokens' => 16000,
                    'system' => ClaudeClient::system($request),
                    'messages' => [['role' => 'user', 'content' => $request->user]],
                    'outputConfig' => ClaudeClient::outputConfig($request),
                ],
            ];
        }

        if ($requests === []) {
            return 0;
        }

        try {
            $remote = app(ClaudeClient::class)->client()->messages->batches->create(requests: $requests);
        } catch (Throwable $e) {
            // Batches unavailable: process these reviews the normal way.
            report($e);
            $items->each(fn (AiBatchItem $item) => $this->fallback($item));

            return 0;
        }

        DB::transaction(function () use ($remote, $requests, $items) {
            $batch = AiBatch::query()->create([
                'anthropic_batch_id' => $remote->id,
                'purpose' => 'analysis',
                'status' => 'submitted',
                'request_count' => count($requests),
                'submitted_at' => now(),
            ]);
            $ids = array_column($requests, 'customID');
            AiBatchItem::query()->whereIn('id', $items->whereIn('review_id', $ids)->pluck('id'))
                ->update(['status' => 'submitted', 'ai_batch_id' => $batch->id]);
        });

        return count($requests);
    }

    /** Collect finished batches. Returns the number of reviews handled. */
    public function collect(): int
    {
        $handled = 0;

        foreach (AiBatch::query()->where('status', 'submitted')->get() as $batch) {
            $client = app(ClaudeClient::class)->client();

            try {
                $remote = $client->messages->batches->retrieve($batch->anthropic_batch_id);
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            if ($remote->processingStatus !== 'ended') {
                continue;
            }

            $items = AiBatchItem::query()->where('ai_batch_id', $batch->id)->get()->keyBy('review_id');

            foreach ($client->messages->batches->resultsStream($batch->anthropic_batch_id) as $result) {
                $item = $items->pull($result->customID);
                if (! $item) {
                    continue;
                }
                $this->handle($item, $result);
                $handled++;
            }

            // Anything without a result line is processed the normal way.
            $items->each(fn (AiBatchItem $item) => $this->fallback($item));
            $batch->forceFill(['status' => 'ended', 'ended_at' => now()])->save();
        }

        return $handled;
    }

    private function handle(AiBatchItem $item, $result): void
    {
        $review = Review::withoutGlobalScopes()->find($item->review_id);
        if (! $review) {
            $item->forceFill(['status' => 'done'])->save();

            return;
        }

        $outcome = $result->result;
        if ($outcome->type !== 'succeeded' || $outcome->message->stopReason !== 'end_turn') {
            $this->fallback($item);

            return;
        }

        $message = $outcome->message;
        $text = null;
        foreach ($message->content as $block) {
            if (($block->type ?? null) === 'text') {
                $text = $block->text;
                break;
            }
        }
        $data = is_string($text) ? json_decode($text, true) : null;
        if (! is_array($data)) {
            $this->fallback($item);

            return;
        }

        $request = $this->analyzer->request($review);
        $generation = $this->recorder->record(
            $request, 'anthropic', $message->model ?: ClaudeClient::model($request), 'ok',
            $message->usage->inputTokens, $message->usage->outputTokens, 0, $data,
            cacheReadTokens: (int) ($message->usage->cacheReadInputTokens ?? 0),
            cacheWriteTokens: (int) ($message->usage->cacheCreationInputTokens ?? 0),
            batched: true,
        );

        try {
            $this->pipeline->completeBatchedAnalysis($review, $data, $generation);
            $item->forceFill(['status' => 'done'])->save();
        } catch (Throwable $e) {
            report($e);
            $this->fallback($item);
        }
    }

    private function fallback(AiBatchItem $item): void
    {
        $item->forceFill(['status' => 'failed'])->save();
        ProcessReview::dispatch($item->review_id, draft: false, backfill: true);
    }
}
