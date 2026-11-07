<?php

namespace App\Models;

use App\Enums\ReplyState;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReviewReply extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'state' => ReplyState::class,
            'scheduled_for' => 'datetime',
            'published_at' => 'datetime',
            'google_policy_rejection' => 'array',
            'attempts' => 'integer',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(ResponseDraft::class, 'draft_id');
    }

    public function attemptsLog(): HasMany
    {
        return $this->hasMany(PublicationAttempt::class, 'reply_id');
    }
}
