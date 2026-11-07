<?php

namespace App\Models;

use App\Enums\DraftStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResponseDraft extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => DraftStatus::class,
            'guardrail_results' => 'array',
            'guardrails_passed' => 'boolean',
            'edit_distance_ratio' => 'float',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class, 'draft_id');
    }

    public function voiceVersion(): BelongsTo
    {
        return $this->belongsTo(BrandVoiceVersion::class, 'brand_voice_version_id');
    }
}
