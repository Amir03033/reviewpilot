<?php

use App\Enums\OrganizationRole;
use App\Enums\ReplyState;
use App\Enums\ReviewState;
use App\Jobs\PublishReply;
use App\Jobs\SyncLocationReviews;
use App\Models\AiGeneration;
use App\Models\GbpLocationLink;
use App\Models\InboundEvent;
use App\Models\Location;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Review;
use App\Models\ReviewReply;
use App\Notifications\WeeklyDigest;
use App\Services\Insights\DashboardStats;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

/*
| ReviewPilot scheduled work. Run the scheduler with `php artisan schedule:work`
| (local) or a cron entry calling `php artisan schedule:run` every minute (production).
*/

Artisan::command('reviewpilot:publish-due', function () {
    $due = ReviewReply::withoutGlobalScopes()
        ->where('state', ReplyState::Scheduled->value)
        ->where('scheduled_for', '<=', now())
        ->limit(200)->get();

    foreach ($due as $reply) {
        $review = Review::withoutGlobalScopes()->find($reply->review_id);
        $org = $review ? Organization::find($review->organization_id) : null;
        $policy = $review ? Location::withoutGlobalScopes()->find($review->location_id)?->automationPolicy : null;

        if (! $review || ! $org || $org->automation_kill_switch || $policy?->isPaused()) {
            // Paused: hand back to a person instead of publishing.
            $reply->forceFill(['state' => ReplyState::Cancelled])->save();
            $review?->transitionTo(ReviewState::PendingApproval);

            continue;
        }

        $reply->forceFill(['state' => ReplyState::Publishing])->save();
        $review->transitionTo(ReviewState::Publishing);
        PublishReply::dispatch($reply->id);
    }

    $this->info("Dispatched {$due->count()} scheduled replies.");
})->purpose('Publish automated replies whose hold window has passed');

Artisan::command('reviewpilot:poll {--mode=poll}', function () {
    $links = GbpLocationLink::withoutGlobalScopes()
        ->whereNotNull('location_id')
        ->whereIn('sync_state', ['active', 'error', 'backfilling'])
        ->pluck('id');

    $links->each(fn ($id) => SyncLocationReviews::dispatch($id, $this->option('mode')));
    $this->info("Queued {$links->count()} locations ({$this->option('mode')}).");
})->purpose('Poll Google for new and updated reviews');

Artisan::command('reviewpilot:prune', function () {
    $aiDays = (int) config('reviewpilot.retention.ai_payload_days');
    AiGeneration::withoutGlobalScopes()->where('created_at', '<', now()->subDays($aiDays))
        ->whereNotNull('request_payload')->update(['request_payload' => null, 'response_payload' => null]);

    InboundEvent::query()->where('received_at', '<', now()->subDays((int) config('reviewpilot.retention.inbound_event_days')))->delete();

    // Hard-delete reviews of locations removed longer ago than the grace period.
    $grace = now()->subDays((int) config('reviewpilot.retention.disconnect_grace_days'));
    $removed = Location::withoutGlobalScopes()->onlyTrashed()->where('deleted_at', '<', $grace)->get();
    foreach ($removed as $location) {
        Review::withoutGlobalScopes()->where('location_id', $location->id)->delete();
        $location->forceDelete();
    }

    $this->info('Pruned retention data.');
})->purpose('Apply data retention rules');

Artisan::command('reviewpilot:purge-organizations', function () {
    $grace = now()->subDays((int) config('reviewpilot.retention.disconnect_grace_days'));

    Organization::onlyTrashed()->where('deleted_at', '<', $grace)->each(function (Organization $org) {
        DB::transaction(fn () => $org->forceDelete()); // FK cascades remove tenant rows
        $this->info("Purged organisation {$org->id}");
    });
})->purpose('Permanently delete organisations after the grace period');

Artisan::command('reviewpilot:weekly-digest', function () {
    Organization::query()->each(function (Organization $org) {
        $recipients = OrganizationMembership::withoutGlobalScopes()->where('organization_id', $org->id)
            ->whereIn('role', [OrganizationRole::Owner->value, OrganizationRole::Admin->value])
            ->with('user')->get()->pluck('user')->filter();

        if ($recipients->isEmpty()) {
            return;
        }

        app(TenantContext::class)->run($org, function () use ($org, $recipients) {
            $stats = new DashboardStats(null);
            $kpis = $stats->kpis(7)['current'];
            $today = $stats->today();
            $topics = collect($stats->topics(7, 3))->where('negative', '>', 0)->pluck('label')->all();

            if ($kpis['reviews'] === 0 && $today['awaiting_approval'] === 0) {
                return;
            }

            foreach ($recipients as $user) {
                $user->notify(new WeeklyDigest($org, [
                    'reviews' => $kpis['reviews'],
                    'average' => $kpis['average_rating'],
                    'replied' => (int) round(($kpis['response_rate'] ?? 0) * $kpis['reviews'] / 100),
                    'pending' => $today['awaiting_approval'],
                    'negative' => $kpis['negative'],
                    'top_topics' => $topics,
                ]));
            }
        });
    });
})->purpose('Send the weekly email digest');

Schedule::command('reviewpilot:publish-due')->everyMinute()->withoutOverlapping();
Schedule::command('reviewpilot:poll')->cron('*/'.max(5, (int) config('reviewpilot.sync.poll_minutes')).' * * * *')->withoutOverlapping();
Schedule::command('reviewpilot:poll --mode=reconcile')->dailyAt('03:17');
Schedule::command('reviewpilot:prune')->dailyAt('04:07');
Schedule::command('reviewpilot:purge-organizations')->dailyAt('04:27');
Schedule::command('reviewpilot:weekly-digest')->weeklyOn(1, '07:43')->timezone('Europe/Amsterdam');
