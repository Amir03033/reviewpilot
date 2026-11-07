<?php

namespace App\Models;

use App\Enums\AutomationMode;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Location extends Model
{
    use BelongsToOrganization, HasFactory, HasUuids, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'group_tags' => 'array',
            'billing_active' => 'boolean',
            'automation_mode' => AutomationMode::class,
        ];
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function gbpLink(): HasOne
    {
        return $this->hasOne(GbpLocationLink::class);
    }

    public function facts(): HasMany
    {
        return $this->hasMany(LocationFact::class);
    }

    public function brandVoice(): HasOne
    {
        return $this->hasOne(BrandVoice::class)->where('scope', 'location');
    }

    public function automationPolicy(): HasOne
    {
        return $this->hasOne(AutomationPolicy::class);
    }

    /** Restrict to the locations the given membership may access. */
    public function scopeAccessibleBy(Builder $query, ?OrganizationMembership $membership): Builder
    {
        if ($membership === null) {
            return $query;
        }

        $ids = $membership->accessibleLocationIds();

        return $ids === null ? $query : $query->whereIn($this->qualifyColumn('id'), $ids);
    }

    public function policy(): AutomationPolicy
    {
        if ($this->automationPolicy) {
            return $this->automationPolicy;
        }

        $policy = AutomationPolicy::withoutGlobalScopes()->firstOrCreate(['location_id' => $this->id], [
            'organization_id' => $this->organization_id,
            'min_rating' => config('reviewpilot.automation.min_rating'),
            'max_risk_score' => config('reviewpilot.automation.max_risk_score'),
            'hold_minutes' => config('reviewpilot.automation.hold_minutes'),
            'daily_cap' => config('reviewpilot.automation.daily_cap'),
            'business_hours_only' => true,
        ])->refresh();

        $this->setRelation('automationPolicy', $policy);

        return $policy;
    }
}
