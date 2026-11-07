<?php

namespace Tests\Feature\Production;

use App\Enums\AutomationMode;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\Billing\BillingGateway;
use App\Services\Billing\BillingService;
use App\Services\Billing\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Billing logic with the Mollie gateway mocked: proves OUR handling, not Mollie's API. */
class BillingLifecycleTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Http::fake();
        $this->gateway = Mockery::mock(BillingGateway::class);
        $this->app->instance(BillingGateway::class, $this->gateway);
    }

    private function paidOrganization(string $status = 'active', array $sub = []): array
    {
        [$owner, $org] = $this->makeOrganization();
        $org->forceFill(['trial_ends_at' => null, 'mollie_customer_id' => 'cst_1', 'plan' => 'pro'])->save();
        $subscription = Subscription::withoutGlobalScopes()->create([
            'organization_id' => $org->id, 'plan' => 'pro', 'status' => $status, 'quantity' => 1, 'amount_cents' => 6900,
            'mollie_subscription_id' => 'sub_1', 'current_period_ends_at' => now()->addMonth(), ...$sub,
        ]);

        return [$owner, $org->fresh(), $subscription];
    }

    private function recurringPayment(string $id, string $status): void
    {
        $this->gateway->shouldReceive('getPayment')->with($id)->andReturn([
            'id' => $id, 'status' => $status, 'sequence_type' => 'recurring', 'amount_cents' => 8349, 'customer_id' => 'cst_1',
            'mandate_id' => 'mdt_1', 'subscription_id' => 'sub_1', 'metadata' => [], 'paid_at' => $status === 'paid' ? now()->toIso8601String() : null,
        ]);
        $this->post('/webhooks/mollie', ['id' => $id])->assertOk();
    }

    public function test_expired_trial_blocks_paid_actions_but_keeps_data_readable(): void
    {
        [$owner, $org] = $this->makeOrganization();
        $location = $this->makeLocation($org, AutomationMode::Manual);
        $review = $this->makeReview($location, 4);
        $org->forceFill(['trial_ends_at' => now()->subDay()])->save();

        $this->actingAs($owner);
        $this->post(route('reviews.reply', $review->id), ['text' => 'Dank je!'])->assertRedirect(route('billing.index'));
        $this->post(route('reviews.regenerate', $review->id))->assertRedirect(route('billing.index'));
        $this->post(route('integrations.google.import'), ['links' => ['x']])->assertRedirect(route('billing.index'));
        $this->get(route('reviews.show', $review->id))->assertOk();
        $this->get(route('dashboard'))->assertOk()->assertInertia(fn ($p) => $p->where('tenant.plan', null));

        $this->assertDatabaseMissing('review_replies', ['review_id' => $review->id]);
        Http::assertNothingSent();
    }

    public function test_failed_recurring_payment_has_a_grace_period_then_locks(): void
    {
        [, $org, $sub] = $this->paidOrganization();

        $this->recurringPayment('tr_fail', 'failed');
        $this->assertSame('past_due', $sub->fresh()->status);
        $this->assertSame('pro', app(Entitlements::class)->plan($org), 'grace period');

        $this->travel(15)->days();
        $this->assertNull(app(Entitlements::class)->plan($org->fresh()), 'locked after grace');

        $this->recurringPayment('tr_paid', 'paid');
        $this->assertSame('active', $sub->fresh()->status);
        $this->assertNull($sub->fresh()->past_due_at);
        $this->assertSame('pro', app(Entitlements::class)->plan($org->fresh()));
        $this->assertSame(2, Payment::withoutGlobalScopes()->count());
    }

    public function test_cancelling_keeps_access_until_the_paid_period_ends(): void
    {
        [$owner, $org, $sub] = $this->paidOrganization();
        $this->gateway->shouldReceive('cancelSubscription')->once()->with('cst_1', 'sub_1');

        $this->actingAs($owner)->post(route('billing.cancel'))->assertRedirect();

        $this->assertSame('canceled', $sub->fresh()->status);
        $this->assertSame('pro', app(Entitlements::class)->plan($org));
        $this->travel(32)->days();
        $this->assertNull(app(Entitlements::class)->plan($org->fresh()));
    }

    public function test_payment_on_a_canceled_subscription_does_not_reactivate_it(): void
    {
        [, , $sub] = $this->paidOrganization('canceled');
        $this->recurringPayment('tr_late', 'paid');
        $this->assertSame('canceled', $sub->fresh()->status);
    }

    public function test_subscription_canceled_at_mollie_is_detected_by_reconcile(): void
    {
        [, $org, $sub] = $this->paidOrganization();
        $this->gateway->shouldReceive('getSubscription')->with('cst_1', 'sub_1')->andReturn(['id' => 'sub_1', 'status' => 'suspended']);

        Artisan::call('reviewpilot:billing-reconcile');

        $this->assertSame('canceled', $sub->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.subscription_ended_remotely']);
    }

    public function test_failed_first_payment_does_not_activate_anything(): void
    {
        config(['mollie.key' => 'test_abc123']);
        [$owner, $org] = $this->makeOrganization();
        $this->gateway->shouldReceive('createCustomer')->andReturn('cst_2');
        $this->gateway->shouldReceive('createFirstPayment')->andReturn(['id' => 'tr_first', 'checkout_url' => 'https://www.mollie.com/checkout/x', 'status' => 'open']);
        $this->actingAs($owner)->post(route('billing.checkout'), ['plan' => 'essentials']);

        $this->gateway->shouldReceive('getPayment')->with('tr_first')->andReturn(['id' => 'tr_first', 'status' => 'expired', 'sequence_type' => 'first', 'amount_cents' => 3509, 'customer_id' => 'cst_2', 'mandate_id' => null, 'subscription_id' => null, 'metadata' => [], 'paid_at' => null]);
        $this->gateway->shouldNotReceive('createSubscription');
        $this->post('/webhooks/mollie', ['id' => 'tr_first'])->assertOk();

        $this->assertSame('canceled', Subscription::withoutGlobalScopes()->sole()->status);
        $this->assertTrue($org->fresh()->onTrial());
        $this->assertSame('pro', app(Entitlements::class)->plan($org->fresh()), 'still on the trial plan');
    }

    public function test_webhook_errors_return_500_so_mollie_retries_and_change_nothing(): void
    {
        [, , $sub] = $this->paidOrganization();
        $this->gateway->shouldReceive('getPayment')->andThrow(new \RuntimeException('Mollie down'));

        $this->post('/webhooks/mollie', ['id' => 'tr_123'])->assertStatus(500);
        $this->assertSame('active', $sub->fresh()->status);
    }

    public function test_forged_webhook_cannot_activate_a_subscription(): void
    {
        // Mollie webhooks carry only an id; we always fetch the real status from Mollie.
        [$owner, $org] = $this->makeOrganization();
        $this->gateway->shouldReceive('getPayment')->with('tr_forged')->andReturn(['id' => 'tr_forged', 'status' => 'paid', 'sequence_type' => 'first', 'amount_cents' => 1, 'customer_id' => 'cst_x', 'mandate_id' => 'm', 'subscription_id' => null, 'metadata' => ['organization_id' => $org->id], 'paid_at' => now()->toIso8601String()]);

        $this->post('/webhooks/mollie', ['id' => 'tr_forged', 'status' => 'paid'])->assertOk();

        $this->assertSame(0, Subscription::withoutGlobalScopes()->count(), 'unknown payments are ignored');
    }

    public function test_per_location_pricing_follows_location_changes(): void
    {
        [$owner, $org, $sub] = $this->paidOrganization();
        $a = $this->makeLocation($org);
        $this->makeLocation($org);

        $this->gateway->shouldReceive('updateSubscriptionAmount')->once()->with('cst_1', 'sub_1', 16698, Mockery::any()); // 2 × €69 + 21% VAT
        app(BillingService::class)->syncQuantity($org);
        $this->assertSame(2, $sub->fresh()->quantity);

        $this->gateway->shouldReceive('updateSubscriptionAmount')->once()->with('cst_1', 'sub_1', 8349, Mockery::any()); // 1 × €69 + VAT
        $this->actingAs($owner)->delete(route('locations.destroy', $a->id));
        $this->assertSame(1, $sub->fresh()->quantity);
    }

    public function test_essentials_cannot_use_autopilot(): void
    {
        [$owner, $org, $sub] = $this->paidOrganization();
        $sub->forceFill(['plan' => 'essentials'])->save();
        $location = $this->makeLocation($org, AutomationMode::Smart);

        $this->actingAs($owner)->patch(route('locations.update', $location->id), ['mode' => 'autopilot']);

        $this->assertSame('smart', $location->fresh()->automation_mode->value);
    }
}
