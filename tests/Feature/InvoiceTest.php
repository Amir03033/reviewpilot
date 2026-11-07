<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscription;
use App\Notifications\InvoiceIssued;
use App\Services\Billing\BillingGateway;
use App\Services\Billing\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Invoice issuing with the Mollie gateway mocked (proves our logic, not Mollie). */
class InvoiceTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->gateway = Mockery::mock(BillingGateway::class);
        $this->app->instance(BillingGateway::class, $this->gateway);
    }

    private function subscribedOrganization(): array
    {
        [$owner, $org] = $this->makeOrganization();
        $org->forceFill([
            'mollie_customer_id' => 'cst_1', 'plan' => 'pro', 'billing_email' => 'boekhouding@example.nl',
            'billing_name' => 'Bistro BV', 'billing_address' => 'Oudegracht 1', 'billing_postal_code' => '3511 AA', 'billing_city' => 'Utrecht',
            'vat_number' => 'NL001234567B01',
        ])->save();
        $sub = Subscription::withoutGlobalScopes()->create([
            'organization_id' => $org->id, 'plan' => 'pro', 'status' => 'active', 'quantity' => 1,
            'amount_cents' => 6900, 'mollie_subscription_id' => 'sub_1', 'current_period_ends_at' => now()->addMonth(),
        ]);

        return [$owner, $org->fresh(), $sub];
    }

    private function webhook(string $id, string $status): void
    {
        $this->gateway->shouldReceive('getPayment')->with($id)->andReturn([
            'id' => $id, 'status' => $status, 'sequence_type' => 'recurring', 'amount_cents' => 8349, 'customer_id' => 'cst_1',
            'mandate_id' => 'mdt_1', 'subscription_id' => 'sub_1', 'metadata' => [], 'paid_at' => now()->toIso8601String(),
        ]);
        $this->post('/webhooks/mollie', ['id' => $id])->assertOk();
    }

    public function test_paid_payment_issues_one_invoice_with_vat_split_and_emails_it(): void
    {
        [, $org] = $this->subscribedOrganization();

        $this->webhook('tr_1', 'paid');
        $this->post('/webhooks/mollie', ['id' => 'tr_1'])->assertOk(); // Mollie retries: still one invoice

        $invoice = Invoice::withoutGlobalScopes()->sole();
        $this->assertSame($org->id, $invoice->organization_id);
        $this->assertSame(8349, $invoice->gross_cents);
        $this->assertSame(6900, $invoice->net_cents);
        $this->assertSame(1449, $invoice->vat_cents);
        $this->assertMatchesRegularExpression('/^RP-\d{4}-0001$/', $invoice->number);
        $this->assertSame('Bistro BV', $invoice->buyer['name']);
        $this->assertSame('NL001234567B01', $invoice->buyer['vat_number']);

        Notification::assertSentOnDemand(InvoiceIssued::class, fn ($n, $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'boekhouding@example.nl');
    }

    public function test_failed_payment_gets_no_invoice(): void
    {
        $this->subscribedOrganization();
        $this->webhook('tr_failed', 'failed');

        $this->assertSame(0, Invoice::withoutGlobalScopes()->count());
    }

    public function test_invoice_numbers_are_sequential_per_year(): void
    {
        [, $org, $sub] = $this->subscribedOrganization();
        $service = app(InvoiceService::class);

        $numbers = collect(range(1, 3))->map(fn ($i) => $service->issueForPayment(Payment::withoutGlobalScopes()->create([
            'organization_id' => $org->id, 'subscription_id' => $sub->id, 'mollie_payment_id' => "tr_seq_{$i}",
            'sequence_type' => 'recurring', 'status' => 'paid', 'amount_cents' => 8349, 'paid_at' => now(),
        ]))->number);

        $year = now()->year;
        $this->assertSame(["RP-{$year}-0001", "RP-{$year}-0002", "RP-{$year}-0003"], $numbers->all());
    }

    public function test_pdf_renders_and_only_billing_roles_can_download_their_own_invoices(): void
    {
        [$owner, $org] = $this->subscribedOrganization();
        $this->webhook('tr_pdf', 'paid');
        $invoice = Invoice::withoutGlobalScopes()->sole();

        $response = $this->actingAs($owner)->get("/billing/invoices/{$invoice->id}")->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));

        [$otherOwner] = $this->makeOrganization(name: 'Other');
        $this->actingAs($otherOwner)->get("/billing/invoices/{$invoice->id}")->assertNotFound();

        $this->actingAs($owner)->get('/billing')->assertInertia(fn ($page) => $page->has('invoices', 1));
    }
}
