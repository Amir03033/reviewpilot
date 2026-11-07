<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'automation_kill_switch' => 'boolean',
            'settings' => 'array',
            'trial_ends_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_memberships')->withPivot('role')->withTimestamps();
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function googleConnections(): HasMany
    {
        return $this->hasMany(GoogleConnection::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription(): HasOne
    {
        // No latestOfMany(): it aggregates MAX(uuid), which PostgreSQL does not support.
        return $this->hasOne(Subscription::class)->whereIn('status', ['active', 'past_due'])->latest();
    }

    public function hasAcceptedCurrentTerms(): bool
    {
        return $this->terms_accepted_at !== null
            && $this->terms_version === config('reviewpilot.legal.terms_version');
    }

    public function acceptTerms(User $user): void
    {
        $this->forceFill([
            'terms_accepted_at' => now(),
            'terms_version' => config('reviewpilot.legal.terms_version'),
            'terms_accepted_by' => $user->id,
        ])->save();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function onTrial(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isFuture();
    }

    public function billableLocationCount(): int
    {
        return $this->locations()->withoutGlobalScopes()->where('organization_id', $this->id)
            ->whereNull('deleted_at')->where('billing_active', true)->count();
    }
}
