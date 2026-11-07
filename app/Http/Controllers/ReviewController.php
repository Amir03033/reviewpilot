<?php

namespace App\Http\Controllers;

use App\Enums\DraftStatus;
use App\Enums\ReviewState;
use App\Models\AuditLog;
use App\Models\DraftFeedback;
use App\Models\Location;
use App\Models\Review;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReviewController extends Controller
{
    public const STATUS_FILTERS = [
        'attention' => 'Needs attention',
        'scheduled' => 'Scheduled',
        'published' => 'Published',
        'no_action' => 'No action',
        'all' => 'All',
    ];

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', array_keys(self::STATUS_FILTERS))],
            'location' => ['nullable', 'string'],
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'topic' => ['nullable', 'string'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $filters['status'] ?? 'attention';

        $reviews = $this->filtered($filters, $status)
            ->with(['analysis:id,review_id,summary,sentiment_label,risk_flags,risk_score', 'location:id,name'])
            ->orderByDesc('priority')
            ->orderByDesc('created_at_source')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Review $r) => $this->listItem($r));

        return Inertia::render('reviews/Index', [
            'reviews' => $reviews,
            'filters' => [...$filters, 'status' => $status],
            'statusOptions' => self::STATUS_FILTERS,
            'locations' => Location::query()->accessibleBy($this->membership())->orderBy('name')->get(['id', 'name']),
            'topics' => Topic::query()->whereNotNull('parent_key')->orderBy('key')->get(['key', 'label_en', 'label_nl'])
                ->map(fn ($t) => ['key' => $t->key, 'label' => $t->label()]),
        ]);
    }

    public function approvals(Request $request): Response
    {
        $reviews = $this->filtered(['location' => $request->string('location')->toString() ?: null], 'attention')
            ->with(['analysis', 'location:id,name', 'proposedDrafts'])
            ->orderByDesc('priority')
            ->orderBy('created_at_source')
            ->limit(50)
            ->get()
            ->map(fn (Review $r) => [
                ...$this->listItem($r),
                'comment' => $r->comment,
                'drafts' => $r->proposedDrafts->sortBy(fn ($d) => array_search($d->variant, ['standard', 'short', 'warm', 'manual']))->values()->map(fn ($d) => $this->draftData($d)),
                'requires_human_reasons' => $r->analysis?->requires_human_reasons ?? [],
                'can_approve' => $this->membership()->canApproveFor($r->location_id),
            ]);

        return Inertia::render('approvals/Index', [
            'items' => $reviews,
            'locations' => Location::query()->accessibleBy($this->membership())->orderBy('name')->get(['id', 'name']),
            'filters' => ['location' => $request->string('location')->toString() ?: null],
        ]);
    }

    public function show(string $id): Response
    {
        $review = Review::query()->with(['location', 'analysis.topicMentions.topic', 'reply', 'revisions'])->findOrFail($id);
        $this->authorizeLocation('view-location', $review->location_id);

        $drafts = $review->drafts()
            ->whereIn('status', [DraftStatus::Proposed->value, DraftStatus::Approved->value])
            ->latest()->get();

        return Inertia::render('reviews/Show', [
            'review' => [
                ...$this->listItem($review),
                'comment' => $review->comment,
                'owner_reply_text' => $review->owner_reply_text,
                'owner_reply_origin' => $review->owner_reply_origin,
                'revisions' => $review->revisions->map->only(['star_rating', 'comment', 'updated_at_source']),
            ],
            'analysis' => $review->analysis ? [
                'language' => $review->analysis->language,
                'sentiment_label' => $review->analysis->sentiment_label,
                'sentiment_score' => $review->analysis->sentiment_score,
                'risk_score' => $review->analysis->risk_score,
                'risk_flags' => $review->analysis->risk_flags ?? [],
                'requires_human' => $review->analysis->requires_human,
                'requires_human_reasons' => $review->analysis->requires_human_reasons ?? [],
                'summary' => $review->analysis->summary,
                'entities' => $review->analysis->entities,
                'topics' => $review->analysis->topicMentions->map(fn ($m) => [
                    'key' => $m->topic?->key,
                    'label' => $m->topic?->label(),
                    'polarity' => $m->polarity,
                    'evidence' => $m->evidence,
                ]),
            ] : null,
            'drafts' => $drafts->where('status', DraftStatus::Proposed)->values()->map(fn ($d) => $this->draftData($d)),
            'reply' => $review->reply ? [
                'text' => $review->reply->text,
                'state' => $review->reply->state->value,
                'scheduled_for' => $review->reply->scheduled_for?->toIso8601String(),
                'published_at' => $review->reply->published_at?->toIso8601String(),
                'published_via' => $review->reply->published_via,
                'last_error' => $review->reply->last_error,
                'policy_rejection' => $review->reply->google_policy_rejection,
            ] : null,
            'timeline' => $this->timeline($review),
            'permissions' => [
                'approve' => $this->membership()->canApproveFor($review->location_id),
                'draft' => $this->membership()->canDraftFor($review->location_id),
            ],
        ]);
    }

    private function filtered(array $filters, string $status): Builder
    {
        return Review::query()
            ->visible()
            ->accessibleBy($this->membership())
            ->when($filters['location'] ?? null, fn ($q, $loc) => $q->where('location_id', $loc))
            ->when($filters['rating'] ?? null, fn ($q, $r) => $q->where('star_rating', $r))
            ->when($filters['topic'] ?? null, fn ($q, $key) => $q->whereHas('topicMentions.topic', fn ($t) => $t->where('key', $key)))
            // LOWER() so search is case-insensitive on PostgreSQL too (LIKE is case-sensitive there).
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->whereRaw('LOWER(comment) LIKE ?', ['%'.addcslashes(mb_strtolower($term), '%_\\').'%'])
                ->orWhereRaw('LOWER(reviewer_display_name) LIKE ?', ['%'.addcslashes(mb_strtolower($term), '%_\\').'%'])))
            ->when($status !== 'all', fn ($q) => match ($status) {
                'attention' => $q->whereIn('workflow_state', ReviewState::inboxAttention()),
                'scheduled' => $q->where('workflow_state', ReviewState::ScheduledAuto->value),
                'published' => $q->whereIn('workflow_state', [ReviewState::Published->value, ReviewState::Publishing->value]),
                'no_action' => $q->whereIn('workflow_state', [ReviewState::NoAction->value, ReviewState::Rejected->value]),
            });
    }

    private function listItem(Review $r): array
    {
        return [
            'id' => $r->id,
            'reviewer' => $r->reviewer_is_anonymous ? __('Anonymous') : ($r->reviewer_display_name ?: __('Google user')),
            'rating' => $r->star_rating,
            'excerpt' => mb_strimwidth((string) $r->comment, 0, 180, '…'),
            'summary' => $r->analysis?->summary,
            'sentiment' => $r->analysis?->sentiment_label,
            'risk_flags' => $r->analysis?->risk_flags ?? [],
            'risk_score' => $r->analysis?->risk_score,
            'location' => ['id' => $r->location_id, 'name' => $r->location?->name],
            'state' => $r->workflow_state->value,
            'state_label' => $r->workflow_state->label(),
            'priority' => $r->priority,
            'created_at' => $r->created_at_source->toIso8601String(),
            'language' => $r->comment_language,
        ];
    }

    private function draftData($d): array
    {
        $checks = collect($d->guardrail_results['checks'] ?? [])->map(fn ($c, $name) => ['name' => $name, ...$c])->values();

        return [
            'id' => $d->id,
            'variant' => $d->variant,
            'text' => $d->text,
            'language' => $d->language,
            'guardrails_passed' => $d->guardrails_passed,
            'failed_checks' => $checks->where('pass', false)->values(),
            'is_ai' => $d->original_text !== null,
            'my_feedback' => match (DraftFeedback::query()->where('draft_id', $d->id)->where('user_id', auth()->id())->value('rating')) {
                1 => 'up',
                -1 => 'down',
                default => null,
            },
        ];
    }

    private function timeline(Review $review): array
    {
        $audit = AuditLog::query()
            ->where('subject_type', 'Review')->where('subject_id', $review->id)
            ->latest('created_at')->limit(50)->get()
            ->map(fn (AuditLog $l) => [
                'at' => $l->created_at->toIso8601String(),
                'action' => $l->action,
                'actor' => $l->actor_type === 'user' ? (User::find($l->actor_id)?->name ?? 'User') : ucfirst($l->actor_type),
                'details' => $l->changes,
            ]);

        $events = collect([
            ['at' => $review->created_at_source->toIso8601String(), 'action' => 'review.posted', 'actor' => 'Guest', 'details' => null],
            ['at' => $review->created_at->toIso8601String(), 'action' => 'review.synced', 'actor' => 'System', 'details' => null],
        ]);

        return $events->concat($audit)->sortByDesc('at')->values()->all();
    }
}
