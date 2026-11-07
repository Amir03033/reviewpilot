<?php

namespace Tests\Feature\ReviewPilot;

use App\Models\Subscription;
use App\Services\Billing\BillingGateway;
use App\Services\Billing\BillingService;
use App\Services\Billing\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_group_plan_uses_minimum_quantity_and_volume_tiers(): void
    {
        $billing = app(BillingService::class);

        $this->assertSame(5, $billing->quote('group', 2)['quantity']);
        $this->assertSame(5900, $billing->quote('group', 5)['unit_cents']);
        $this->assertSame(5400, $billing->quote('group', 12)['unit_cents']);
        $this->assertSame(4900, $billing->quote('group', 30)['unit_cents']);

        $pro = $billing->quote('pro', 3);
        $this->assertSame(20700, $pro['net_cents']);
        $this->assertSame(25047, $pro['gross_cents']); // incl. 21% VAT
    }

    public function test_checkout_and_first_payment_webhook_activate_a_mollie_subscription(): void
    {
        config(['mollie.key' => 'test_realkey123']);
        [$owner, $org] = $this->makeOrganization();
        $this->makeLocation($org);
        $this->makeLocation($org);

        $gateway = Mockery::mock(BillingGateway::class);
        $gateway->shouldReceive('createCustomer')->once()->andReturn('cst_1');
        $gateway->shouldReceive('createFirstPayment')->once()
            ->withArgs(fn ($customer, $amount) => $customer === 'cst_1' && $amount === 16698) // 2 × €69 + VAT
            ->andReturn(['id' => 'tr_first', 'checkout_url' => 'https://www.mollie.com/checkout/tr_first', 'status' => 'open']);
        $this->app->instance(BillingGateway::class, $gateway);

        $response = $this->actingAs($owner)->post(route('billing.checkout'), ['plan' => 'pro']);
        $this->assertStringContainsString('mollie.com/checkout', $response->headers->get('Location') ?? $response->headers->get('X-Inertia-Location'));

        $gateway->shouldReceive('getPayment')->with('tr_first')->andReturn([
            'id' => 'tr_first', 'status' => 'paid', 'sequence_type' => 'first', 'amount_cents' => 16698,
            'customer_id' => 'cst_1', 'mandate_id' => 'mdt_1', 'subscription_id' => null, 'metadata' => [], 'paid_at' => now()->toIso8601String(),
        ]);
        $gateway->shouldReceive('createSubscription')->once()->andReturn(['id' => 'sub_1', 'status' => 'active']);

        $this->post('/webhooks/mollie', ['id' => 'tr_first'])->assertOk();
        $this->post('/webhooks/mollie', ['id' => 'tr_first'])->assertOk(); // idempotent

        $subscription = Subscription::withoutGlobalScopes()->where('organization_id', $org->id)->sole();
        $this->assertSame('active', $subscription->status);
        $this->assertSame('sub_1', $subscription->mollie_subscription_id);
        $this->assertSame('mdt_1', $org->fresh()->mollie_mandate_id);
        $this->assertSame('pro', app(Entitlements::class)->plan($org->fresh()));
        $this->assertFalse($org->fresh()->onTrial());

        // Adding a location updates the Mollie subscription amount.
        $gateway->shouldReceive('updateSubscriptionAmount')->once()->withArgs(fn ($c, $s, $amount) => $s === 'sub_1' && $amount === 25047);
        $this->makeLocation($org);
        app(BillingService::class)->syncQuantity($org->fresh());
        $this->assertSame(3, $subscription->fresh()->quantity);
    }

    public function test_webhook_ignores_unknown_ids(): void
    {
        $this->post('/webhooks/mollie', ['id' => 'not-a-payment'])->assertOk();
    }
}
