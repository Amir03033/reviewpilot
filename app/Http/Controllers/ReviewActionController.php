<?php

namespace App\Http\Controllers;

use App\Enums\ReviewState;
use App\Jobs\RegenerateDrafts;
use App\Models\ResponseDraft;
use App\Models\Review;
use App\Services\Reviews\ReplyPublisher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ReviewActionController extends Controller
{
    public function __construct(private ReplyPublisher $publisher) {}

    public function approve(Request $request, string $draftId): RedirectResponse
    {
        $draft = ResponseDraft::query()->with('review')->findOrFail($draftId);
        $this->authorizeLocation('approve-for-location', $draft->location_id);

        $data = $request->validate(['text' => ['nullable', 'string', 'max:4000']]);

        return $this->attempt(fn () => $this->publisher->approve($draft, $request->user(), $data['text'] ?? null), 'Reply approved and sent to Google.');
    }

    public function manual(Request $request, string $reviewId): RedirectResponse
    {
        $review = Review::query()->findOrFail($reviewId);
        $this->authorizeLocation('approve-for-location', $review->location_id);

        $data = $request->validate(['text' => ['required', 'string', 'min:2', 'max:4000']]);

        return $this->attempt(fn () => $this->publisher->submitManual($review, $request->user(), $data['text']), 'Reply sent to Google.');
    }

    public function regenerate(Request $request, string $reviewId): RedirectResponse
    {
        $review = Review::query()->findOrFail($reviewId);
        $this->authorizeLocation('draft-for-location', $review->location_id);

        $data = $request->validate(['hint' => ['nullable', 'string', 'max:300']]);

        $review->transitionTo(ReviewState::Drafting);
        RegenerateDrafts::dispatch($review->id, (string) ($data['hint'] ?? ''));

        return $this->backWith('Writing new drafts…');
    }

    public function reject(Request $request, string $reviewId): RedirectResponse
    {
        $review = Review::query()->findOrFail($reviewId);
        $this->authorizeLocation('approve-for-location', $review->location_id);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $this->publisher->reject($review, $request->user(), $data['note'] ?? null);

        return $this->backWith('Marked as “no reply”.');
    }

    public function cancelScheduled(Request $request, string $reviewId): RedirectResponse
    {
        $review = Review::query()->findOrFail($reviewId);
        $this->authorizeLocation('approve-for-location', $review->location_id);

        return $this->attempt(fn () => $this->publisher->cancelScheduled($review, $request->user()), 'Automatic reply cancelled. It is back in the approval queue.');
    }

    public function retry(string $reviewId): RedirectResponse
    {
        $review = Review::query()->findOrFail($reviewId);
        $this->authorizeLocation('approve-for-location', $review->location_id);

        return $this->attempt(fn () => $this->publisher->retry($review), 'Publishing again…');
    }

    private function attempt(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (InvalidArgumentException $e) {
            return $this->backWith($e->getMessage(), 'error');
        }

        return $this->backWith($success);
    }
}
