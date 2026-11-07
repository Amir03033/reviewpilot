<?php

namespace App\Http\Controllers\Webhooks;

use App\Jobs\ProcessGbpNotification;
use App\Models\InboundEvent;
use App\Services\Google\PubSubTokenVerifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Pub/Sub push endpoint for Business Profile review notifications.
 * Always answers quickly; processing happens on the queue.
 */
class GooglePubSubController
{
    public function __invoke(Request $request, PubSubTokenVerifier $verifier): Response
    {
        if (! $verifier->verify($request->header('Authorization'))) {
            return response('', 401);
        }

        $message = $request->input('message', []);
        $messageId = $message['messageId'] ?? $message['message_id'] ?? null;

        if (! $messageId) {
            return response('', 204);
        }

        $data = json_decode((string) base64_decode($message['data'] ?? '', true), true) ?: [];

        try {
            $event = InboundEvent::create([
                'source' => 'google_pubsub',
                'external_id' => (string) $messageId,
                'payload' => ['data' => $data, 'attributes' => $message['attributes'] ?? [], 'subscription' => $request->input('subscription')],
            ]);
        } catch (UniqueConstraintViolationException) {
            return response('', 204); // duplicate delivery
        }

        ProcessGbpNotification::dispatch($event->id);

        return response('', 204);
    }
}
