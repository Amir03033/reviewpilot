<?php

namespace App\Http\Controllers\Webhooks;

use App\Jobs\ProcessGbpNotification;
use App\Models\InboundEvent;
use App\Services\Google\PubSubTokenVerifier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

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

        // Pub/Sub delivers at least once: insert-or-ignore on (source, external_id) so a
        // duplicate delivery is a no-op without raising (and aborting) a DB error.
        $id = (string) Str::uuid7();
        $inserted = InboundEvent::query()->insertOrIgnore([
            'id' => $id,
            'source' => 'google_pubsub',
            'external_id' => (string) $messageId,
            'payload' => json_encode(['data' => $data, 'attributes' => $message['attributes'] ?? [], 'subscription' => $request->input('subscription')]),
            'received_at' => now(),
        ]);

        if ($inserted === 0) {
            return response('', 204); // duplicate delivery
        }

        $event = InboundEvent::query()->findOrFail($id);
        ProcessGbpNotification::dispatch($event->id);

        return response('', 204);
    }
}
