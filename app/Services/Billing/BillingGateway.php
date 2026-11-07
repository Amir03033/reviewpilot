<?php

namespace App\Services\Billing;

/**
 * Payment provider abstraction. Production implementation: MollieBillingGateway.
 * All amounts are in cents, VAT included.
 */
interface BillingGateway
{
    public function createCustomer(string $name, string $email, array $metadata = []): string;

    /**
     * First payment that also creates a recurring mandate (iDEAL, card, Bancontact…).
     *
     * @return array{id: string, checkout_url: ?string, status: string}
     */
    public function createFirstPayment(string $customerId, int $amountCents, string $description, string $redirectUrl, string $webhookUrl, array $metadata = []): array;

    /**
     * @return array{id: string, status: string, sequence_type: ?string, amount_cents: int, customer_id: ?string, mandate_id: ?string, subscription_id: ?string, metadata: array, paid_at: ?string}
     */
    public function getPayment(string $paymentId): array;

    /** @return array{id: string, status: string} */
    public function createSubscription(string $customerId, int $amountCents, string $description, string $webhookUrl, string $startDate, array $metadata = []): array;

    public function updateSubscriptionAmount(string $customerId, string $subscriptionId, int $amountCents, string $description): void;

    public function cancelSubscription(string $customerId, string $subscriptionId): void;

    /** @return array{id: string, status: string} */
    public function getSubscription(string $customerId, string $subscriptionId): array;
}
