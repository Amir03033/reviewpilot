<?php

namespace App\Services\Billing;

use Mollie\Api\MollieApiClient;

/**
 * Mollie implementation (iDEAL / SEPA Direct Debit / cards).
 * Flow: first payment (sequenceType=first) creates a mandate; a Mollie subscription
 * then charges the mandate monthly via SEPA Direct Debit or card.
 */
class MollieBillingGateway implements BillingGateway
{
    public function __construct(private MollieApiClient $mollie) {}

    public function createCustomer(string $name, string $email, array $metadata = []): string
    {
        return $this->mollie->customers->create([
            'name' => $name,
            'email' => $email,
            'metadata' => $metadata,
        ])->id;
    }

    public function createFirstPayment(string $customerId, int $amountCents, string $description, string $redirectUrl, string $webhookUrl, array $metadata = []): array
    {
        $payment = $this->mollie->payments->create([
            'amount' => $this->money($amountCents),
            'description' => $description,
            'customerId' => $customerId,
            'sequenceType' => 'first',
            'redirectUrl' => $redirectUrl,
            'webhookUrl' => $webhookUrl,
            'metadata' => $metadata,
        ]);

        return ['id' => $payment->id, 'checkout_url' => $payment->getCheckoutUrl(), 'status' => (string) $payment->status];
    }

    public function getPayment(string $paymentId): array
    {
        $payment = $this->mollie->payments->get($paymentId);

        return [
            'id' => $payment->id,
            'status' => (string) $payment->status,
            'sequence_type' => $payment->sequenceType,
            'amount_cents' => (int) round(((float) $payment->amount->value) * 100),
            'customer_id' => $payment->customerId,
            'mandate_id' => $payment->mandateId,
            'subscription_id' => $payment->subscriptionId,
            'metadata' => json_decode(json_encode($payment->metadata ?? []), true) ?: [],
            'paid_at' => $payment->paidAt ?? null,
        ];
    }

    public function createSubscription(string $customerId, int $amountCents, string $description, string $webhookUrl, string $startDate, array $metadata = []): array
    {
        $subscription = $this->mollie->subscriptions->createForId($customerId, [
            'amount' => $this->money($amountCents),
            'interval' => '1 month',
            'description' => $description,
            'webhookUrl' => $webhookUrl,
            'startDate' => $startDate,
            'metadata' => $metadata,
        ]);

        return ['id' => $subscription->id, 'status' => (string) $subscription->status];
    }

    public function updateSubscriptionAmount(string $customerId, string $subscriptionId, int $amountCents, string $description): void
    {
        $this->mollie->subscriptions->update($customerId, $subscriptionId, [
            'amount' => $this->money($amountCents),
            'description' => $description,
        ]);
    }

    public function cancelSubscription(string $customerId, string $subscriptionId): void
    {
        $this->mollie->subscriptions->cancelForId($customerId, $subscriptionId);
    }

    public function getSubscription(string $customerId, string $subscriptionId): array
    {
        $subscription = $this->mollie->subscriptions->getForId($customerId, $subscriptionId);

        return ['id' => $subscription->id, 'status' => (string) $subscription->status];
    }

    /** @return array{currency: string, value: string} */
    private function money(int $cents): array
    {
        return ['currency' => config('reviewpilot.billing.currency', 'EUR'), 'value' => number_format($cents / 100, 2, '.', '')];
    }
}
