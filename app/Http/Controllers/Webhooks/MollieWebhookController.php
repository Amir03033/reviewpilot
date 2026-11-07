<?php

namespace App\Http\Controllers\Webhooks;

use App\Services\Billing\BillingService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * Mollie posts only a payment id; the real status is always fetched from the Mollie API,
 * so a forged request cannot change billing state.
 */
class MollieWebhookController
{
    public function __invoke(Request $request, BillingService $billing): Response
    {
        $id = (string) $request->input('id');

        if (! preg_match('/^tr_[A-Za-z0-9]+$/', $id)) {
            return response('', 200); // ignore unknown resources (Mollie expects 200)
        }

        try {
            $billing->handlePaymentWebhook($id);
        } catch (Throwable $e) {
            report($e);

            return response('', 500); // Mollie retries
        }

        return response('', 200);
    }
}
