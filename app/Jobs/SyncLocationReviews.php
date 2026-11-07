<?php

namespace App\Jobs;

use App\Models\GbpLocationLink;
use App\Models\Location;
use App\Models\Review;
use App\Models\SyncRun;
use App\Services\Google\GbpClient;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleRateLimited;
use App\Services\Google\GoogleReauthRequired;
use App\Services\Reviews\ReviewIngestor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pulls reviews for one location from Google.
 *   poll      – newest first, stops at the last seen update time (cheap; usually one call)
 *   backfill  – the full history (first import)
 *   reconcile – full walk; also detects reviews deleted on Google
 */
class SyncLocationReviews implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 900;

    public function __construct(public string $linkId, public string $mode = 'poll')
    {
        $this->onQueue($mode === 'backfill' ? 'backfill' : 'sync');
    }

    public function uniqueId(): string
    {
        return $this->linkId.':'.$this->mode;
    }

    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(GbpClient $client, ReviewIngestor $ingestor): void
    {
        $link = GbpLocationLink::withoutGlobalScopes()->with('account.connection')->find($this->linkId);
        $location = $link?->location_id ? Location::withoutGlobalScopes()->find($link->location_id) : null;

        if (! $link || ! $location || $location->status !== 'active') {
            return;
        }

        $run = SyncRun::withoutGlobalScopes()->create([
            'organization_id' => $link->organization_id,
            'location_id' => $location->id,
            'kind' => $this->mode,
        ]);

        $cursor = $link->last_review_update_time;
        $newestSeen = $cursor;
        $seenIds = [];
        $pageToken = null;
        $calls = 0;
        $upserted = 0;

        try {
            do {
                $page = $client->listReviews($link->account->connection, $link->v4_parent, $pageToken);
                $calls++;
                $reachedCursor = false;

                foreach ($page['reviews'] as $review) {
                    $seenIds[] = $review->reviewId;

                    if ($this->mode === 'poll' && $cursor && $review->updateTime->lte($cursor)) {
                        $reachedCursor = true;
                        break;
                    }

                    $ingestor->ingest($location, $review, isBackfill: $this->mode === 'backfill');
                    $upserted++;

                    if (! $newestSeen || $review->updateTime->gt($newestSeen)) {
                        $newestSeen = $review->updateTime;
                    }
                }

                $pageToken = $reachedCursor ? null : $page['nextPageToken'];
            } while ($pageToken);

            if ($this->mode === 'reconcile') {
                Review::withoutGlobalScopes()
                    ->where('location_id', $location->id)
                    ->whereNull('removed_at')
                    ->whereNotIn('external_id', $seenIds ?: ['-'])
                    ->update(['removed_at' => now()]);
            }

            $link->forceFill([
                'sync_state' => 'active',
                'last_synced_at' => now(),
                'last_review_update_time' => $newestSeen,
                'last_error' => null,
            ])->save();

            $run->forceFill(['status' => 'ok', 'api_calls' => $calls, 'reviews_upserted' => $upserted, 'finished_at' => now()])->save();
        } catch (GoogleRateLimited $e) {
            $run->forceFill(['status' => 'failed', 'error' => 'rate_limited', 'api_calls' => $calls, 'finished_at' => now()])->save();
            $this->release($e->retryAfterSeconds + 5);
        } catch (GoogleReauthRequired $e) {
            $link->forceFill(['sync_state' => 'error', 'last_error' => 'Google connection needs to be reconnected.'])->save();
            $run->forceFill(['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now()])->save();
        } catch (GoogleApiException $e) {
            $run->forceFill(['status' => 'failed', 'error' => $e->getMessage(), 'api_calls' => $calls, 'finished_at' => now()])->save();

            if ($e->isPermissionDenied() || $e->isNotFound()) {
                $link->forceFill(['sync_state' => 'error', 'last_error' => 'Google no longer gives access to this location ('.$e->status.').'])->save();

                return;
            }

            $link->forceFill(['last_error' => $e->getMessage()])->save();
            throw $e;
        }
    }
}
