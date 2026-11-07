<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Subscription;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;

/**
 * Issues VAT invoices for paid Mollie payments.
 *
 * Mollie charges the gross amount (net + VAT, see BillingService::quote()), so the
 * invoice splits the paid amount back into net and VAT at the configured rate.
 * Numbers are gap-free per year (RP-2026-0001) thanks to a locked counter row.
 */
class InvoiceService
{
    /** Idempotent: a payment gets at most one invoice. */
    public function issueForPayment(Payment $payment): ?Invoice
    {
        if ($payment->status !== 'paid' || $payment->amount_cents <= 0) {
            return null;
        }

        return DB::transaction(function () use ($payment) {
            $existing = Invoice::withoutGlobalScopes()->where('payment_id', $payment->id)->first();
            if ($existing) {
                return $existing;
            }

            $organization = Organization::withTrashed()->findOrFail($payment->organization_id);
            $subscription = $payment->subscription_id ? Subscription::withoutGlobalScopes()->find($payment->subscription_id) : null;
            $issuedOn = ($payment->paid_at ?? now())->copy();

            $rate = (float) config('reviewpilot.billing.vat_rate');
            $gross = (int) $payment->amount_cents;
            $net = (int) round($gross / (1 + $rate));
            $vat = $gross - $net;

            return Invoice::withoutGlobalScopes()->create([
                'organization_id' => $organization->id,
                'payment_id' => $payment->id,
                'number' => $this->nextNumber((int) $issuedOn->format('Y')),
                'issued_on' => $issuedOn->toDateString(),
                'net_cents' => $net,
                'vat_cents' => $vat,
                'gross_cents' => $gross,
                'vat_rate' => $rate,
                'currency' => $payment->currency ?? 'EUR',
                'seller' => $this->seller(),
                'buyer' => $this->buyer($organization),
                'lines' => [[
                    'description' => $this->lineDescription($subscription, $issuedOn),
                    'quantity' => $subscription?->quantity ?? 1,
                    'net_cents' => $net,
                ]],
            ]);
        });
    }

    public function pdf(Invoice $invoice): string
    {
        return Pdf::loadView('invoices.pdf', ['invoice' => $invoice])->setPaper('a4')->output();
    }

    private function nextNumber(int $year): string
    {
        DB::table('invoice_counters')->insertOrIgnore(['year' => $year, 'last_number' => 0]);
        $counter = DB::table('invoice_counters')->where('year', $year)->lockForUpdate()->first();
        $number = $counter->last_number + 1;
        DB::table('invoice_counters')->where('year', $year)->update(['last_number' => $number]);

        return sprintf('RP-%d-%04d', $year, $number);
    }

    /** @return array<string, ?string> */
    private function seller(): array
    {
        return collect(config('reviewpilot.company'))
            ->only(['legal_name', 'address', 'postal_code', 'city', 'country', 'kvk', 'vat_number', 'iban', 'email'])
            ->all();
    }

    /** @return array<string, ?string> */
    private function buyer(Organization $organization): array
    {
        return [
            'name' => $organization->billing_name ?: $organization->name,
            'address' => $organization->billing_address,
            'postal_code' => $organization->billing_postal_code,
            'city' => $organization->billing_city,
            'country' => $organization->country,
            'vat_number' => $organization->vat_number,
            'email' => $organization->billing_email,
        ];
    }

    private function lineDescription(?Subscription $subscription, $date): string
    {
        if (! $subscription) {
            return 'ReviewPilot';
        }

        $plan = config("reviewpilot.plans.{$subscription->plan}.name", ucfirst($subscription->plan));
        $from = $date->copy();
        $to = $date->copy()->addMonth()->subDay();

        return sprintf('ReviewPilot %s – %d locatie(s), periode %s t/m %s', $plan, $subscription->quantity, $from->format('d-m-Y'), $to->format('d-m-Y'));
    }
}
