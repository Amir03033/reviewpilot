<?php

namespace App\Models;

use App\Enums\DraftStatus;
use App\Enums\ReviewState;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Review extends Model
{
    use BelongsToOrganization, HasFactory, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reviewer_is_anonymous' => 'boolean',
            'star_rating' => 'integer',
            'created_at_source' => 'datetime',
            'updated_at_source' => 'datetime',
            'owner_reply_updated_at' => 'datetime',
            'removed_at' => 'datetime',
            'workflow_state' => ReviewState::class,
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ReviewRevision::class);
    }

    public function analyses(): HasMany
    {
        return $this->hasMany(ReviewAnalysis::class);
    }

    public function analysis(): HasOne
    {
        // Exactly one analysis is current (ReviewAnalyzer flips the flag in a transaction).
        // Note: latestOfMany() would aggregate MAX(id), which PostgreSQL does not support for UUIDs.
        return $this->hasOne(ReviewAnalysis::class)->where('is_current', true);
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(ResponseDraft::class);
    }

    public function proposedDrafts(): HasMany
    {
        return $this->hasMany(ResponseDraft::class)->where('status', DraftStatus::Proposed->value);
    }

    public function reply(): HasOne
    {
        return $this->hasOne(ReviewReply::class);
    }

    public function topicMentions(): HasMany
    {
        return $this->hasMany(ReviewTopicMention::class);
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereNull('removed_at');
    }

    public function scopeAccessibleBy(Builder $query, ?OrganizationMembership $membership): Builder
    {
        $ids = $membership?->accessibleLocationIds();

        return $ids === null ? $query : $query->whereIn('location_id', $ids);
    }

    public function hasText(): bool
    {
        return trim((string) $this->comment) !== '';
    }

    public function transitionTo(ReviewState $state): void
    {
        $this->forceFill(['workflow_state' => $state])->save();
    }
}
