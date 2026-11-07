<?php

namespace App\Services\Reviews;

use App\Enums\DraftStatus;
use App\Models\DraftFeedback;
use App\Models\Location;
use App\Models\ResponseDraft;
use App\Models\Review;
use Illuminate\Support\Collection;

/**
 * Learning from the team: replies they approved, corrections they made to AI drafts and
 * thumbs-down feedback become part of the drafting prompt for that location.
 *
 * The output only changes when the team approves or rates something, so the system
 * prompt stays identical between those moments and keeps hitting the prompt cache.
 */
class StyleExamples
{
    /** A draft counts as "corrected" when at least this share of it was changed. */
    private const CORRECTION_RATIO = 0.15;

    private const MAX_CHARS = 600;

    /**
     * @return array{corrections: list<array{ai: string, team: string}>, approved: list<array{rating: int, review: string, reply: string}>, feedback: list<string>}
     */
    public function for(Location $location): array
    {
        $limit = max(0, (int) config('reviewpilot.ai.style_examples', 6));
        if ($limit === 0) {
            return ['corrections' => [], 'approved' => [], 'feedback' => []];
        }

        $approved = $this->approvedDrafts($location, $limit * 3);

        $corrections = $approved
            ->filter(fn (ResponseDraft $d) => $d->original_text !== null && (float) $d->edit_distance_ratio >= self::CORRECTION_RATIO)
            ->take(intdiv($limit, 2));

        $examples = $approved->reject(fn (ResponseDraft $d) => $corrections->contains('id', $d->id))
            ->take($limit - $corrections->count());

        $reviews = Review::withoutGlobalScopes()
            ->whereIn('id', $corrections->pluck('review_id')->merge($examples->pluck('review_id')))
            ->get(['id', 'star_rating', 'comment'])->keyBy('id');

        return [
            'corrections' => $corrections->map(fn (ResponseDraft $d) => [
                'ai' => $this->clip((string) $d->original_text),
                'team' => $this->clip((string) $d->text),
            ])->values()->all(),
            'approved' => $examples->map(fn (ResponseDraft $d) => [
                'rating' => (int) ($reviews[$d->review_id]->star_rating ?? 0),
                'review' => $this->clip((string) ($reviews[$d->review_id]->comment ?? ''), 200),
                'reply' => $this->clip((string) $d->text),
            ])->values()->all(),
            'feedback' => $this->feedback($location),
        ];
    }

    /** @return Collection<int, ResponseDraft> */
    private function approvedDrafts(Location $location, int $take): Collection
    {
        $query = fn () => ResponseDraft::withoutGlobalScopes()
            ->where('organization_id', $location->organization_id)
            ->where('status', DraftStatus::Approved->value)
            ->orderByDesc('updated_at')->orderBy('id');

        $own = $query()->where('location_id', $location->id)->limit($take)->get();

        // New location: borrow the organisation's style until it has its own history.
        if ($own->count() < 3) {
            $own = $own->concat($query()->where('location_id', '!=', $location->id)->limit($take - $own->count())->get());
        }

        return $own->values();
    }

    /** @return list<string> */
    private function feedback(Location $location): array
    {
        return DraftFeedback::withoutGlobalScopes()
            ->where('location_id', $location->id)
            ->where('rating', -1)
            ->where('created_at', '>=', now()->subDays(90))
            ->orderByDesc('created_at')->orderBy('id')
            ->limit(6)->get()
            ->map(fn (DraftFeedback $f) => trim(str_replace('_', ' ', (string) $f->reason).($f->comment ? ': '.$this->clip($f->comment, 200) : '')))
            ->filter()->unique()->values()->all();
    }

    private function clip(string $text, int $max = self::MAX_CHARS): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max).'…' : $text;
    }
}
