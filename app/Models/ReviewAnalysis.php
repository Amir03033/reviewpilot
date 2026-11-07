<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReviewAnalysis extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'language_confidence' => 'float',
            'sentiment_score' => 'float',
            'risk_score' => 'integer',
            'risk_flags' => 'array',
            'requires_human' => 'boolean',
            'requires_human_reasons' => 'array',
            'confidence' => 'float',
            'entities' => 'array',
            'keywords' => 'array',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function topicMentions(): HasMany
    {
        return $this->hasMany(ReviewTopicMention::class, 'analysis_id');
    }
}
