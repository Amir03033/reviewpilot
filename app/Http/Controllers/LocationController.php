<?php

namespace App\Http\Controllers;

use App\Enums\AutomationMode;
use App\Jobs\SyncLocationReviews;
use App\Models\GbpLocationLink;
use App\Models\Location;
use App\Models\LocationFact;
use App\Services\Audit\AuditLogger;
use App\Services\Automation\AutomationEvaluator;
use App\Services\Billing\BillingService;
use App\Services\Billing\Entitlements;
use App\Services\Insights\DashboardStats;
use App\Services\Reviews\BrandVoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LocationController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): Response
    {
        $locations = Location::query()->accessibleBy($this->membership())->with('gbpLink')->orderBy('name')->get();

        return Inertia::render('locations/Index', [
            'locations' => $locations->map(fn (Location $l) => [
                'id' => $l->id,
                'name' => $l->name,
                'city' => $l->city,
                'status' => $l->status,
                'mode' => $l->automation_mode->value,
                'google' => $l->gbpLink ? [
                    'verified' => $l->gbpLink->is_verified,
                    'sync_state' => $l->gbpLink->sync_state,
                    'last_synced_at' => $l->gbpLink->last_synced_at?->toIso8601String(),
                    'last_error' => $l->gbpLink->last_error,
                ] : null,
            ]),
            'canManage' => $this->membership()->role->canManageOrganization(),
        ]);
    }

    public function show(string $id, BrandVoiceService $voices, Entitlements $entitlements): Response
    {
        $location = Location::query()->with(['gbpLink', 'facts'])->findOrFail($id);
        $this->authorizeLocation('view-location', $location->id);
        $policy = $location->policy();
        $override = $voices->locationVoice($location)?->currentVersion;

        return Inertia::render('locations/Show', [
            'location' => [
                ...$location->only(['id', 'name', 'address_line', 'city', 'postal_code', 'country', 'timezone', 'status']),
                'mode' => $location->automation_mode->value,
                'group_tags' => $location->group_tags ?? [],
                'google' => $location->gbpLink?->only(['title', 'is_verified', 'sync_state', 'last_synced_at', 'last_error', 'place_id']),
            ],
            'facts' => $location->facts->map->only(['id', 'key', 'value', 'source'])->values(),
            'policy' => $policy->only(['min_rating', 'max_risk_score', 'hold_minutes', 'daily_cap', 'business_hours_only', 'allowed_languages', 'paused_until']),
            'voiceOverride' => $override ? [
                'settings' => $override->settings,
                'forbidden_phrases' => $override->forbidden_phrases,
                'preferred_phrases' => $override->preferred_phrases,
                'guidelines' => $override->guidelines,
                'special_instructions' => $override->special_instructions,
            ] : null,
            'effectiveVoice' => $voices->resolve($location),
            'stats' => (new DashboardStats($this->membership(), $location->id))->kpis(30),
            'allowedModes' => collect(AutomationMode::cases())->map(fn ($m) => [
                'value' => $m->value,
                'allowed' => $m === AutomationMode::Manual || $entitlements->allowsMode($this->organization(), $m->value),
            ]),
            'canManage' => $this->membership()->canApproveFor($location->id),
        ]);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $location = Location::query()->findOrFail($id);
        $this->authorizeLocation('approve-for-location', $location->id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'timezone' => ['sometimes', 'timezone'],
            'mode' => ['sometimes', Rule::enum(AutomationMode::class)],
            'group_tags' => ['sometimes', 'array'],
            'group_tags.*' => ['string', 'max:40'],
        ]);

        if (isset($data['mode']) && $data['mode'] !== 'manual' && ! app(Entitlements::class)->allowsMode($this->organization(), $data['mode'])) {
            return $this->backWith('Your plan does not include this automation mode.', 'error');
        }

        $before = $location->only(['name', 'automation_mode', 'timezone']);
        $location->fill(collect($data)->except('mode')->all());
        if (isset($data['mode'])) {
            $location->automation_mode = AutomationMode::from($data['mode']);
        }
        $location->save();

        $this->audit->log('location.updated', $location, ['before' => $before, 'after' => $location->only(['name', 'automation_mode', 'timezone'])]);

        return $this->backWith('Location saved.');
    }

    public function updatePolicy(Request $request, string $id): RedirectResponse
    {
        $location = Location::query()->findOrFail($id);
        $this->authorizeLocation('approve-for-location', $location->id);

        $data = $request->validate([
            'min_rating' => ['required', 'integer', 'between:3,5'],
            'max_risk_score' => ['required', 'integer', 'between:0,60'],
            'hold_minutes' => ['required', 'integer', 'min:'.config('reviewpilot.automation.min_hold_minutes'), 'max:2880'],
            'daily_cap' => ['required', 'integer', 'between:1,200'],
            'business_hours_only' => ['required', 'boolean'],
            'allowed_languages' => ['nullable', 'array'],
            'allowed_languages.*' => ['string', 'size:2'],
            'paused' => ['sometimes', 'boolean'],
        ]);

        $policy = $location->policy();
        $policy->fill(collect($data)->except('paused')->all());
        if (array_key_exists('paused', $data)) {
            $policy->paused_until = $data['paused'] ? now()->addYears(10) : null;
            $policy->paused_reason = $data['paused'] ? 'Paused by '.$request->user()->name : null;
        }
        $policy->updated_by = $request->user()->id;
        $policy->save();

        $this->audit->log('automation.policy_updated', $location, $data);

        return $this->backWith('Automation settings saved.');
    }

    public function updateFacts(Request $request, string $id): RedirectResponse
    {
        $location = Location::query()->findOrFail($id);
        $this->authorizeLocation('approve-for-location', $location->id);

        $data = $request->validate([
            'facts' => ['array', 'max:40'],
            'facts.*.key' => ['required', 'string', 'max:60', 'distinct'],
            'facts.*.value' => ['required', 'string', 'max:500'],
        ]);

        LocationFact::query()->where('location_id', $location->id)->delete();
        foreach ($data['facts'] ?? [] as $fact) {
            LocationFact::query()->create([
                'location_id' => $location->id,
                'key' => str($fact['key'])->snake()->toString(),
                'value' => $fact['value'],
                'source' => 'user',
            ]);
        }

        $this->audit->log('location.facts_updated', $location, ['count' => count($data['facts'] ?? [])]);

        return $this->backWith('Facts saved. The AI will only use these facts in replies.');
    }

    public function sync(string $id): RedirectResponse
    {
        $location = Location::query()->with('gbpLink')->findOrFail($id);
        $this->authorizeLocation('approve-for-location', $location->id);

        if (! $location->gbpLink) {
            return $this->backWith('This location is not connected to Google.', 'error');
        }

        SyncLocationReviews::dispatch($location->gbpLink->id, 'reconcile');

        return $this->backWith('Sync started.');
    }

    public function destroy(string $id, BillingService $billing): RedirectResponse
    {
        $this->authorizeManage();
        $location = Location::query()->findOrFail($id);

        GbpLocationLink::query()->where('location_id', $location->id)->update(['location_id' => null, 'sync_state' => 'pending']);
        $location->forceFill(['status' => 'disconnected', 'billing_active' => false])->save();
        $location->delete();

        $this->audit->log('location.removed', $location);
        rescue(fn () => $billing->syncQuantity($this->organization()));

        $this->toast('Location removed. Its data is deleted after '.config('reviewpilot.retention.disconnect_grace_days').' days.');

        return redirect()->route('locations.index');
    }

    public function simulate(string $id, AutomationEvaluator $evaluator): array
    {
        // Lightweight preview for the automation settings screen.
        $location = Location::query()->findOrFail($id);
        $this->authorizeLocation('view-location', $location->id);

        return ['publish_at_example' => $evaluator->publishAt($location, $location->policy()->hold_minutes, $location->policy()->business_hours_only)->toIso8601String()];
    }
}
