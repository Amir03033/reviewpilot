<?php

namespace App\Services\Billing;

use App\Models\Organization;
use App\Models\Subscription;
use App\Models\UsageRecord;

/** What an organisation's plan (or trial) allows. */
class Entitlements
{
    /** The effective plan key, or null when the organisation has neither a trial nor a subscription. */
    public function plan(Organization $organization): ?string
    {
        $subscription = Subscription::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->whereIn('status', ['active', 'past_due'])
            ->latest()
            ->first();

        if ($subscription) {
            return $subscription->plan;
        }

        return $organization->onTrial() ? config('reviewpilot.trial_plan') : null;
    }

    public function isActive(Organization $organization): bool
    {
        return $this->plan($organization) !== null;
    }

    /** @return array<string, mixed>|null */
    public function planConfig(Organization $organization): ?array
    {
        $plan = $this->plan($organization);

        return $plan ? config("reviewpilot.plans.{$plan}") : null;
    }

    public function allowsMode(Organization $organization, string $mode): bool
    {
        return in_array($mode, $this->planConfig($organization)['modes'] ?? ['manual'], true);
    }

    public function hasFeature(Organization $organization, string $feature): bool
    {
        return in_array($feature, $this->planConfig($organization)['features'] ?? [], true);
    }

    public function aiReviewAllowance(Organization $organization): int
    {
        $perLocation = (int) ($this->planConfig($organization)['ai_reviews_per_location'] ?? 0);

        return $perLocation * max(1, $organization->billableLocationCount());
    }

    public function aiReviewsUsedThisMonth(Organization $organization): int
    {
        return (int) UsageRecord::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('metric', 'reviews_processed')
            ->where('period', now()->startOfMonth()->toDateString())
            ->value('quantity');
    }

    public function canProcessWithAi(Organization $organization): bool
    {
        return $this->isActive($organization)
            && $this->aiReviewsUsedThisMonth($organization) < $this->aiReviewAllowance($organization);
    }
}
