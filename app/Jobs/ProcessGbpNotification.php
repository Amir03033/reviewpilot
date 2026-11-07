<?php

namespace App\Jobs;

use App\Models\GbpLocationLink;
use App\Models\InboundEvent;
use App\Models\Location;
use App\Services\Google\GbpClient;
use App\Services\Google\GoogleRateLimited;
use App\Services\Reviews\ReviewIngestor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Handles a Pub/Sub NEW_REVIEW / UPDATED_REVIEW notification. The payload only names
 * the review, so we fetch it from the API.
 */
class ProcessGbpNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [10, 60, 300];

    public function __construct(public string $inboundEventId)
    {
        $this->onQueue('realtime');
    }

    public function handle(GbpClient $client, ReviewIngestor $ingestor): void
    {
        $event = InboundEvent::find($this->inboundEventId);

        if (! $event || $event->processed_at) {
            return;
        }

        $data = $event->payload['data'] ?? [];
        $type = $data['type'] ?? $data['notificationType'] ?? null;
        $reviewName = $data['review'] ?? $data['reviewName'] ?? null;
        $locationName = $data['location'] ?? $data['locationName'] ?? null;

        if (! in_array($type, ['NEW_REVIEW', 'UPDATED_REVIEW'], true) || ! $reviewName) {
            $event->forceFill(['processed_at' => now(), 'error' => 'ignored'])->save();

            return;
        }

        // locationName is accounts/{a}/locations/{l}; match on the locations/{l} suffix too.
        $suffix = $locationName ? 'locations/'.basename($locationName) : null;
        $link = GbpLocationLink::withoutGlobalScopes()->with('account.connection')
            ->whereNotNull('location_id')
            ->where(fn ($q) => $q->where('v4_parent', $locationName)->orWhere('gbp_location_name', $suffix))
            ->first();

        if (! $link) {
            $event->forceFill(['processed_at' => now(), 'error' => 'unknown location'])->save();

            return;
        }

        try {
            $review = $client->getReview($link->account->connection, $reviewName);
        } catch (GoogleRateLimited $e) {
            $this->release($e->retryAfterSeconds + 1);

            return;
        }

        $ingestor->ingest(Location::withoutGlobalScopes()->findOrFail($link->location_id), $review);
        $event->forceFill(['processed_at' => now(), 'error' => null])->save();
    }
}
