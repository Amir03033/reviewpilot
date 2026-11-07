<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DraftFeedback extends Model
{
    use BelongsToOrganization, HasUuids;

    public const REASONS = ['tone', 'wrong_facts', 'too_long', 'too_short', 'too_generic', 'wrong_language', 'other'];

    protected $table = 'draft_feedback';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rating' => 'integer'];
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(ResponseDraft::class, 'draft_id');
    }
}
