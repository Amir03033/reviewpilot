<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationPolicy extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'min_rating' => 'integer',
            'max_risk_score' => 'integer',
            'allowed_languages' => 'array',
            'hold_minutes' => 'integer',
            'daily_cap' => 'integer',
            'business_hours_only' => 'boolean',
            'paused_until' => 'datetime',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function isPaused(): bool
    {
        return $this->paused_until !== null && $this->paused_until->isFuture();
    }
}
