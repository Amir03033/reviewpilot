<?php

namespace App\Services\Automation;

use App\Enums\AutomationMode;
use App\Enums\ReplyState;
use App\Models\Location;
use App\Models\Organization;
use App\Models\ResponseDraft;
use App\Models\Review;
use App\Models\ReviewAnalysis;
use App\Models\ReviewReply;
use App\Models\ReviewRevision;
use App\Services\Billing\Entitlements;
use App\Support\PlatformSettings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Decides whether a draft may be published without a human. Hard gates cannot be
 * configured away. See docs/blueprint/04-feature-specification.md §C2.
 */
class AutomationEvaluator
{
    public function __construct(private Entitlements $entitlements) {}

    public function evaluate(Review $review, ReviewAnalysis $analysis, ResponseDraft $draft): AutomationDecision
    {
        $location = Location::withoutGlobalScopes()->findOrFail($review->location_id);
        $organization = Organization::findOrFail($review->organization_id);
        $policy = $location->policy();
        $cfg = config('reviewpilot.automation');
        $reasons = [];

        $mode = $location->automation_mode;
        if ($mode === AutomationMode::Autopilot && ! $this->entitlements->allowsMode($organization, 'autopilot')) {
            $mode = AutomationMode::Smart;
        }
        if ($mode !== AutomationMode::Manual && ! $this->entitlements->allowsMode($organization, $mode->value)) {
            $mode = AutomationMode::Manual;
        }

        // --- Hard gates (never configurable) ---
        if ($mode === AutomationMode::Manual) {
            $reasons[] = 'Location is in manual mode.';
        }
        if (PlatformSettings::automationPaused()) {
            $reasons[] = 'Automatic publishing is paused platform-wide.';
        }
        if ($organization->automation_kill_switch) {
            $reasons[] = 'Automation is paused for the organisation.';
        }
        if ($policy->isPaused()) {
            $reasons[] = 'Automation is paused for this location.';
        }
        if ($review->star_rating <= $cfg['hard_gate_max_rating']) {
            $reasons[] = "Rating of {$review->star_rating} stars always needs a person.";
        }
        $hardFlags = array_intersect($analysis->risk_flags ?? [], $cfg['hard_gate_flags']);
        if ($hardFlags !== []) {
            $reasons[] = 'Sensitive topic: '.implode(', ', $hardFlags).'.';
        }
        if ($analysis->requires_human) {
            $reasons[] = 'Analysis marked this review for human attention.';
        }
        if (! $draft->guardrails_passed) {
            $reasons[] = 'The draft did not pass all guardrails.';
        }
        if ($draft->guardrail_results['requires_human'] ?? false) {
            $reasons[] = 'The draft mentions compensation.';
        }
        if ($analysis->confidence < $cfg['min_confidence']) {
            $reasons[] = 'Analysis confidence is too low.';
        }
        if (ReviewRevision::withoutGlobalScopes()->where('review_id', $review->id)->where('star_rating', '<=', 3)->exists()) {
            $reasons[] = 'This review was previously negative.';
        }

        // --- Mode thresholds ---
        $minRating = $mode === AutomationMode::Autopilot ? max(3, $policy->min_rating) : max(4, $policy->min_rating);
        $maxRisk = $mode === AutomationMode::Autopilot ? $policy->max_risk_score : min($cfg['max_risk_score'], $policy->max_risk_score);

        if ($review->star_rating < $minRating) {
            $reasons[] = "Rating below the automation threshold ({$minRating}+).";
        }
        if ($analysis->risk_score > $maxRisk) {
            $reasons[] = "Risk score {$analysis->risk_score} is above the limit ({$maxRisk}).";
        }
        if (! empty($policy->allowed_languages) && $draft->language && ! in_array(substr($draft->language, 0, 2), $policy->allowed_languages, true)) {
            $reasons[] = "Language {$draft->language} is not enabled for automation.";
        }
        if ($this->publishedTodayByAutomation($location) >= $policy->daily_cap) {
            $reasons[] = 'Daily automation limit reached.';
        }

        if ($reasons !== []) {
            return new AutomationDecision(false, array_values(array_unique($reasons)));
        }

        return new AutomationDecision(true, [], $this->publishAt($location, max($policy->hold_minutes, $cfg['min_hold_minutes']), $policy->business_hours_only));
    }

    /**
     * Last-moment safety check right before an automated reply goes to Google, against
     * the review and analysis as they are NOW (they may have changed since scheduling).
     *
     * @return array<int, string> reasons to stop (empty = safe)
     */
    public function publishTimeBlockers(Review $review): array
    {
        $cfg = config('reviewpilot.automation');
        $reasons = [];
        $analysis = ReviewAnalysis::withoutGlobalScopes()->where('review_id', $review->id)->where('is_current', true)->latest()->first();
        $organization = Organization::find($review->organization_id);
        $policy = Location::withoutGlobalScopes()->find($review->location_id)?->automationPolicy;

        if (PlatformSettings::automationPaused()) {
            $reasons[] = 'platform_paused';
        }
        if (! $organization || $organization->automation_kill_switch) {
            $reasons[] = 'organization_paused';
        }
        if ($policy?->isPaused()) {
            $reasons[] = 'location_paused';
        }
        if ($review->removed_at) {
            $reasons[] = 'review_removed';
        }
        if ($review->star_rating <= $cfg['hard_gate_max_rating']) {
            $reasons[] = 'low_rating';
        }
        if (! $analysis || $analysis->requires_human || array_intersect($analysis->risk_flags ?? [], $cfg['hard_gate_flags']) !== []) {
            $reasons[] = 'sensitive';
        }
        if ($review->owner_reply_origin === 'external') {
            $reasons[] = 'already_answered_on_google';
        }

        return $reasons;
    }

    private function publishedTodayByAutomation(Location $location): int
    {
        return ReviewReply::withoutGlobalScopes()
            ->where('published_via', 'automation')
            ->whereIn('state', [ReplyState::Scheduled->value, ReplyState::Publishing->value, ReplyState::Published->value])
            ->where('created_at', '>=', now()->startOfDay())
            ->whereIn('review_id', Review::withoutGlobalScopes()->where('location_id', $location->id)->select('id'))
            ->count();
    }

    /** Hold window, then shift into business hours (location time) if required. */
    public function publishAt(Location $location, int $holdMinutes, bool $businessHoursOnly): CarbonInterface
    {
        $at = CarbonImmutable::now($location->timezone)->addMinutes($holdMinutes);

        if ($businessHoursOnly) {
            ['start' => $start, 'end' => $end] = config('reviewpilot.automation.business_hours');

            if ($at->hour < $start) {
                $at = $at->setTime($start, 0);
            } elseif ($at->hour >= $end) {
                $at = $at->addDay()->setTime($start, 0);
            }
        }

        return $at->utc();
    }
}
