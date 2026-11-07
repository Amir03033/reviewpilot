<?php

namespace App\Services\Insights;

use App\Enums\ReviewState;
use App\Models\Location;
use App\Models\OrganizationMembership;
use App\Models\Review;
use App\Models\ReviewReply;
use App\Models\ReviewTopicMention;
use App\Models\Topic;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * KPI and trend queries for the overview and insights screens.
 * (Computed live; move to pre-aggregated daily tables once volume requires it.)
 */
class DashboardStats
{
    public function __construct(private ?OrganizationMembership $membership, private ?string $locationId = null) {}

    public function kpis(int $days = 30): array
    {
        $now = CarbonImmutable::now();
        $current = $this->periodStats($now->subDays($days), $now);
        $previous = $this->periodStats($now->subDays($days * 2), $now->subDays($days));

        return [
            'current' => $current,
            'previous' => $previous,
            'days' => $days,
        ];
    }

    private function periodStats(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $reviews = $this->reviews()->whereBetween('created_at_source', [$from, $to]);
        $count = (clone $reviews)->count();
        $average = $count ? round((float) (clone $reviews)->avg('star_rating'), 2) : null;
        $replied = (clone $reviews)->whereNotNull('owner_reply_text')->count();

        $responseMinutes = ReviewReply::query()
            ->whereIn('review_id', (clone $reviews)->select('id'))
            ->whereNotNull('published_at')
            ->join('reviews', 'reviews.id', '=', 'review_replies.review_id')
            ->get(['review_replies.published_at', 'reviews.created_at_source'])
            ->map(fn ($r) => CarbonImmutable::parse($r->created_at_source)->diffInMinutes(CarbonImmutable::parse($r->published_at)))
            ->sort()->values();

        return [
            'reviews' => $count,
            'average_rating' => $average,
            'response_rate' => $count ? round($replied / $count * 100, 1) : null,
            'median_response_hours' => $responseMinutes->isEmpty() ? null : round($responseMinutes->median() / 60, 1),
            'negative' => (clone $reviews)->where('star_rating', '<=', 2)->count(),
        ];
    }

    /** Daily average rating and volume for the last N days. */
    public function trend(int $days = 90): array
    {
        $from = CarbonImmutable::now()->subDays($days)->startOfDay();

        $rows = $this->reviews()
            ->where('created_at_source', '>=', $from)
            ->get(['created_at_source', 'star_rating'])
            ->groupBy(fn ($r) => $r->created_at_source->toDateString());

        $series = [];
        for ($d = $from; $d->lte(CarbonImmutable::now()); $d = $d->addDay()) {
            $key = $d->toDateString();
            $group = $rows->get($key, collect());
            $series[] = [
                'date' => $key,
                'count' => $group->count(),
                'average' => $group->isEmpty() ? null : round($group->avg('star_rating'), 2),
            ];
        }

        return $series;
    }

    /** Topics by mention count in the period, with net sentiment and change vs the previous period. */
    public function topics(int $days = 30, int $limit = 8): array
    {
        $now = CarbonImmutable::now();
        $current = $this->topicCounts($now->subDays($days), $now);
        $previous = $this->topicCounts($now->subDays($days * 2), $now->subDays($days));
        $labels = Topic::query()->whereIn('id', array_keys($current))->get()->keyBy('id');

        return collect($current)
            ->map(function ($c, $topicId) use ($previous, $labels) {
                $prevNeg = $previous[$topicId]['negative'] ?? 0;

                return [
                    'key' => $labels[$topicId]->key ?? '',
                    'label' => $labels[$topicId]?->label() ?? '',
                    'mentions' => $c['total'],
                    'positive' => $c['positive'],
                    'negative' => $c['negative'],
                    'net' => $c['total'] ? round(($c['positive'] - $c['negative']) / $c['total'], 2) : 0,
                    'negative_change' => $c['negative'] - $prevNeg,
                ];
            })
            ->sortByDesc('mentions')->take($limit)->values()->all();
    }

    private function topicCounts(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $query = ReviewTopicMention::query()
            ->whereBetween('review_created_at', [$from, $to])
            ->whereIn('review_id', $this->reviews()->select('id'));

        return $query->get(['topic_id', 'polarity'])
            ->groupBy('topic_id')
            ->map(fn (Collection $g) => [
                'total' => $g->count(),
                'positive' => $g->where('polarity', 1)->count(),
                'negative' => $g->where('polarity', -1)->count(),
            ])->all();
    }

    /** Per-location comparison table. */
    public function locations(int $days = 30): array
    {
        $from = CarbonImmutable::now()->subDays($days);

        return Location::query()->accessibleBy($this->membership)->orderBy('name')->get()
            ->map(function (Location $location) use ($from) {
                $reviews = Review::query()->visible()->where('location_id', $location->id)->where('created_at_source', '>=', $from);
                $count = (clone $reviews)->count();

                return [
                    'id' => $location->id,
                    'name' => $location->name,
                    'city' => $location->city,
                    'mode' => $location->automation_mode->value,
                    'reviews' => $count,
                    'average_rating' => $count ? round((float) (clone $reviews)->avg('star_rating'), 2) : null,
                    'response_rate' => $count ? round((clone $reviews)->whereNotNull('owner_reply_text')->count() / $count * 100, 1) : null,
                    'waiting' => Review::query()->visible()->where('location_id', $location->id)->whereIn('workflow_state', ReviewState::needsAttention())->count(),
                ];
            })->all();
    }

    public function today(): array
    {
        $base = $this->reviews();

        return [
            'awaiting_approval' => (clone $base)->whereIn('workflow_state', ReviewState::needsAttention())->count(),
            'oldest_waiting_at' => (clone $base)->whereIn('workflow_state', ReviewState::needsAttention())->min('created_at_source'),
            'high_risk_open' => (clone $base)->whereIn('workflow_state', ReviewState::needsAttention())->where('priority', '>=', 60)->count(),
            'scheduled' => (clone $base)->where('workflow_state', ReviewState::ScheduledAuto->value)->count(),
            'published_24h' => ReviewReply::query()->whereIn('review_id', (clone $base)->select('id'))->where('published_at', '>=', now()->subDay())->count(),
        ];
    }

    private function reviews(): Builder
    {
        return Review::query()
            ->visible()
            ->accessibleBy($this->membership)
            ->when($this->locationId, fn ($q) => $q->where('location_id', $this->locationId));
    }
}
