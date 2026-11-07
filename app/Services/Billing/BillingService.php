<?php

namespace App\Services\Billing;

use App\Models\Organization;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Per-location subscriptions on Mollie.
 */
class BillingService
{
    public function __construct(private BillingGateway $gateway, private AuditLogger $audit) {}

    public function isConfigured(): bool
    {
        $key = (string) config('mollie.key');

        return $key !== '' && ! str_contains($key, 'xxxxxxxx');
    }

    /**
     * @return array{plan: string, quantity: int, unit_cents: int, net_cents: int, vat_cents: int, gross_cents: int}
     */
    public function quote(string $plan, int $locations): array
    {
        $config = config("reviewpilot.plans.{$plan}") ?? throw new InvalidArgumentException("Unknown plan {$plan}.");
        $quantity = max($locations, (int) ($config['min_locations'] ?? 1), 1);
        $unit = $this->unitPrice($plan, $quantity);
        $net = $unit * $quantity;
        $vat = (int) round($net * (float) config('reviewpilot.billing.vat_rate'));

        return ['plan' => $plan, 'quantity' => $quantity, 'unit_cents' => $unit, 'net_cents' => $net, 'vat_cents' => $vat, 'gross_cents' => $net + $vat];
    }

    /** Group plan volume tiers: 5–9 €59, 10–24 €54, 25+ €49 per location. */
    public function unitPrice(string $plan, int $quantity): int
    {
        $base = (int) config("reviewpilot.plans.{$plan}.price_per_location");

        if ($plan !== 'group') {
            return $base;
        }

        return match (true) {
            $quantity >= 25 => 4900,
            $quantity >= 10 => 5400,
            default => $base,
        };
    }

    /** Start a checkout; returns the Mollie checkout URL. */
    public function startCheckout(Organization $organization, string $plan, string $email): string
    {
        $quote = $this->quote($plan, $organization->billableLocationCount());

        if (! $organization->mollie_customer_id) {
            $organization->forceFill([
                'mollie_customer_id' => $this->gateway->createCustomer($organization->name, $email, ['organization_id' => $organization->id]),
            ])->save();
        }

        $subscription = Subscription::withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'plan' => $plan,
            'status' => 'pending',
            'quantity' => $quote['quantity'],
            'amount_cents' => $quote['net_cents'],
            'currency' => config('reviewpilot.billing.currency'),
        ]);

        $payment = $this->gateway->createFirstPayment(
            customerId: $organization->mollie_customer_id,
            amountCents: $quote['gross_cents'],
            description: $this->description($plan, $quote['quantity']),
            redirectUrl: route('billing.return', ['subscription' => $subscription->id]),
            webhookUrl: route('webhooks.mollie'),
            metadata: ['organization_id' => $organization->id, 'subscription_id' => $subscription->id],
        );

        Payment::withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'subscription_id' => $subscription->id,
            'mollie_payment_id' => $payment['id'],
            'sequence_type' => 'first',
            'status' => $payment['status'],
            'amount_cents' => $quote['gross_cents'],
            'description' => $this->description($plan, $quote['quantity']),
        ]);

        $this->audit->log('billing.checkout_started', $subscription, ['plan' => $plan, 'quantity' => $quote['quantity']], organizationId: $organization->id);

        return (string) $payment['checkout_url'];
    }

    /** Mollie webhook: Mollie only sends the payment id; we always fetch the real state from the API. */
    public function handlePaymentWebhook(string $paymentId): void
    {
        $data = $this->gateway->getPayment($paymentId);

        DB::transaction(function () use ($data) {
            $payment = Payment::withoutGlobalScopes()->where('mollie_payment_id', $data['id'])->lockForUpdate()->first();
            $subscription = null;

            if ($payment) {
                $subscription = $payment->subscription_id ? Subscription::withoutGlobalScopes()->find($payment->subscription_id) : null;
            } elseif ($data['subscription_id']) {
                // Recurring payment created by a Mollie subscription: first time we see it.
                $subscription = Subscription::withoutGlobalScopes()->where('mollie_subscription_id', $data['subscription_id'])->first();
                if (! $subscription) {
                    return;
                }
                $payment = Payment::withoutGlobalScopes()->create([
                    'organization_id' => $subscription->organization_id,
                    'subscription_id' => $subscription->id,
                    'mollie_payment_id' => $data['id'],
                    'sequence_type' => 'recurring',
                    'status' => $data['status'],
                    'amount_cents' => $data['amount_cents'],
                ]);
            } else {
                return;
            }

            $wasPaid = $payment->status === 'paid';
            $payment->forceFill([
                'status' => $data['status'],
                'paid_at' => $data['status'] === 'paid' ? ($data['paid_at'] ?? now()) : $payment->paid_at,
            ])->save();

            if (! $subscription || $wasPaid && $data['status'] === 'paid') {
                return;
            }

            $organization = Organization::findOrFail($subscription->organization_id);

            match (true) {
                $payment->sequence_type === 'first' && $data['status'] === 'paid' => $this->activate($organization, $subscription, $data),
                $payment->sequence_type === 'first' && in_array($data['status'], ['failed', 'canceled', 'expired'], true) => $subscription->forceFill(['status' => 'canceled', 'canceled_at' => now()])->save(),
                $payment->sequence_type === 'recurring' && $data['status'] === 'paid' => $subscription->forceFill(['status' => 'active', 'current_period_ends_at' => now()->addMonth()])->save(),
                $payment->sequence_type === 'recurring' && in_array($data['status'], ['failed', 'charged_back'], true) => $subscription->forceFill(['status' => 'past_due'])->save(),
                default => null,
            };
        });
    }

    private function activate(Organization $organization, Subscription $subscription, array $payment): void
    {
        // Cancel any other active subscription (plan change).
        Subscription::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('id', '!=', $subscription->id)
            ->whereIn('status', ['active', 'past_due'])
            ->get()
            ->each(fn (Subscription $old) => $this->cancelSubscription($organization, $old));

        $organization->forceFill([
            'mollie_mandate_id' => $payment['mandate_id'],
            'plan' => $subscription->plan,
            'trial_ends_at' => null,
        ])->save();

        $quote = $this->quote($subscription->plan, $subscription->quantity);
        $mollie = $this->gateway->createSubscription(
            customerId: $organization->mollie_customer_id,
            amountCents: $quote['gross_cents'],
            description: $this->description($subscription->plan, $subscription->quantity),
            webhookUrl: route('webhooks.mollie'),
            startDate: now()->addMonth()->toDateString(),
            metadata: ['organization_id' => $organization->id, 'subscription_id' => $subscription->id],
        );

        $subscription->forceFill([
            'status' => 'active',
            'mollie_subscription_id' => $mollie['id'],
            'current_period_ends_at' => now()->addMonth(),
        ])->save();

        $this->audit->log('billing.subscription_activated', $subscription, ['plan' => $subscription->plan], actorType: 'system', organizationId: $organization->id);
    }

    /** Keep the Mollie subscription amount in line with the number of active locations. */
    public function syncQuantity(Organization $organization): void
    {
        $subscription = Subscription::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->whereIn('status', ['active', 'past_due'])
            ->latest()->first();

        if (! $subscription || ! $subscription->mollie_subscription_id) {
            return;
        }

        $quote = $this->quote($subscription->plan, $organization->billableLocationCount());

        if ($quote['quantity'] === $subscription->quantity) {
            return;
        }

        $this->gateway->updateSubscriptionAmount(
            $organization->mollie_customer_id,
            $subscription->mollie_subscription_id,
            $quote['gross_cents'],
            $this->description($subscription->plan, $quote['quantity']),
        );

        $subscription->forceFill(['quantity' => $quote['quantity'], 'amount_cents' => $quote['net_cents']])->save();
        $this->audit->log('billing.quantity_changed', $subscription, ['quantity' => $quote['quantity']], organizationId: $organization->id);
    }

    public function cancelSubscription(Organization $organization, Subscription $subscription): void
    {
        if ($subscription->mollie_subscription_id && $organization->mollie_customer_id) {
            rescue(fn () => $this->gateway->cancelSubscription($organization->mollie_customer_id, $subscription->mollie_subscription_id));
        }

        $subscription->forceFill(['status' => 'canceled', 'canceled_at' => now()])->save();
        $this->audit->log('billing.subscription_canceled', $subscription, [], organizationId: $organization->id);
    }

    private function description(string $plan, int $quantity): string
    {
        $name = config("reviewpilot.plans.{$plan}.name");

        return "ReviewPilot {$name} – {$quantity} ".($quantity === 1 ? 'location' : 'locations');
    }
}
