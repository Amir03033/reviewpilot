<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Subscription;
use App\Models\UsageRecord;
use App\Services\Billing\BillingService;
use App\Services\Billing\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class BillingController extends Controller
{
    public function __construct(private BillingService $billing, private Entitlements $entitlements) {}

    public function index(): Response
    {
        $organization = $this->organization();
        $locations = $organization->billableLocationCount();

        return Inertia::render('billing/Index', [
            'configured' => $this->billing->isConfigured(),
            'plan' => $this->entitlements->plan($organization),
            'onTrial' => $organization->onTrial(),
            'trialEndsAt' => $organization->trial_ends_at?->toIso8601String(),
            'locations' => $locations,
            'plans' => collect(config('reviewpilot.plans'))->map(fn ($p, $key) => [
                'key' => $key,
                'name' => $p['name'],
                'price_per_location' => $p['price_per_location'],
                'min_locations' => $p['min_locations'] ?? 1,
                'ai_reviews_per_location' => $p['ai_reviews_per_location'],
                'modes' => $p['modes'],
                'quote' => $this->billing->quote($key, max(1, $locations)),
            ])->values(),
            'subscription' => Subscription::query()->whereIn('status', ['active', 'past_due', 'pending'])->latest()->first()
                ?->only(['id', 'plan', 'status', 'quantity', 'amount_cents', 'current_period_ends_at']),
            'payments' => Payment::query()->latest()->limit(12)->get()->map->only(['id', 'status', 'amount_cents', 'description', 'paid_at', 'created_at']),
            'usage' => [
                'ai_reviews' => $this->entitlements->aiReviewsUsedThisMonth($organization),
                'ai_allowance' => $this->entitlements->aiReviewAllowance($organization),
                'ai_cost_microcents' => (int) UsageRecord::query()->where('metric', 'ai_cost_microcents')->where('period', now()->startOfMonth()->toDateString())->value('quantity'),
            ],
            'vatRate' => (float) config('reviewpilot.billing.vat_rate'),
            'canBill' => Gate::allows('manage-billing'),
        ]);
    }

    public function checkout(Request $request): SymfonyResponse
    {
        Gate::authorize('manage-billing');
        $data = $request->validate(['plan' => ['required', Rule::in(array_keys(config('reviewpilot.plans')))]]);

        if (! $this->billing->isConfigured()) {
            return $this->backWith('Payments are not configured yet. Add MOLLIE_KEY to .env.', 'error');
        }

        $url = $this->billing->startCheckout($this->organization(), $data['plan'], $this->organization()->billing_email ?? $request->user()->email);

        return Inertia::location($url);
    }

    public function return(string $subscription): RedirectResponse
    {
        $sub = Subscription::query()->findOrFail($subscription);

        match ($sub->status) {
            'active' => $this->toast('Payment received – your subscription is active. Thank you!'),
            'pending' => $this->toast('Payment is being processed. This page updates once Mollie confirms it.', 'info'),
            default => $this->toast('The payment was not completed.', 'error'),
        };

        return redirect()->route('billing.index');
    }

    public function cancel(): RedirectResponse
    {
        Gate::authorize('manage-billing');
        $sub = Subscription::query()->whereIn('status', ['active', 'past_due'])->latest()->firstOrFail();
        $this->billing->cancelSubscription($this->organization(), $sub);

        return $this->backWith('Subscription cancelled.');
    }
}
