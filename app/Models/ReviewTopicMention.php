<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewTopicMention extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['polarity' => 'integer', 'review_created_at' => 'datetime'];
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }
}
