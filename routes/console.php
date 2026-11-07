<?php

use App\Enums\OrganizationRole;
use App\Enums\ReplyState;
use App\Enums\ReviewState;
use App\Jobs\GenerateLocationInsight;
use App\Jobs\PublishReply;
use App\Jobs\SyncLocationReviews;
use App\Models\AiGeneration;
use App\Models\DataExport;
use App\Models\GbpLocationLink;
use App\Models\InboundEvent;
use App\Models\Location;
use App\Models\LocationInsight;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Review;
use App\Models\ReviewReply;
use App\Models\User;
use App\Notifications\WeeklyDigest;
use App\Services\Ai\BatchAnalyzer;
use App\Services\Billing\BillingService;
use App\Services\Billing\Entitlements;
use App\Services\Insights\DashboardStats;
use App\Support\PlatformSettings;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Minishlink\WebPush\VAPID;

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

        if (! $review || ! $org || $org->automation_kill_switch || $policy?->isPaused() || PlatformSettings::automationPaused()) {
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

Artisan::command('reviewpilot:weekly-insights', function (Entitlements $entitlements) {
    $count = 0;
    Organization::query()->each(function (Organization $org) use ($entitlements, &$count) {
        if (! $entitlements->isActive($org)) {
            return;
        }
        Location::withoutGlobalScopes()->where('organization_id', $org->id)->where('status', 'active')
            ->pluck('id')->each(function (string $id) use (&$count) {
                GenerateLocationInsight::dispatch($id);
                $count++;
            });
    });
    $this->info("Queued advice for {$count} location(s).");
})->purpose('Write the weekly AI advice per location');

Artisan::command('reviewpilot:ai-batches', function (BatchAnalyzer $batches) {
    $collected = $batches->collect();
    $submitted = $batches->submit();
    $this->info("Batches: {$submitted} review(s) submitted, {$collected} result(s) processed.");
})->purpose('Submit and collect Message Batches API work (first-import analysis)');

Artisan::command('reviewpilot:webpush-keys', function () {
    $keys = VAPID::createVapidKeys();
    $this->line('Add these two lines to .env, then run php artisan optimize:');
    $this->newLine();
    $this->line('WEBPUSH_PUBLIC_KEY='.$keys['publicKey']);
    $this->line('WEBPUSH_PRIVATE_KEY='.$keys['privateKey']);
    $this->newLine();
    $this->warn('Never change these keys later: existing phone subscriptions would stop working.');
})->purpose('Generate the key pair for push notifications');

Artisan::command('reviewpilot:make-admin {email} {--revoke}', function (string $email) {
    $user = User::query()->where('email', $email)->first();
    if (! $user) {
        $this->error("No user with email {$email}. Register in the app first.");

        return 1;
    }
    $user->forceFill(['is_platform_admin' => ! $this->option('revoke')])->save();
    $this->info($this->option('revoke') ? "{$email} is no longer a platform admin." : "{$email} can now open /admin.");
    if (! $this->option('revoke') && ! $user->two_factor_confirmed_at && config('reviewpilot.admin.require_two_factor')) {
        $this->warn('Turn on two-factor authentication (Settings → Security) first: /admin requires it.');
    }

    return 0;
})->purpose('Give (or with --revoke remove) access to the internal admin panel');

Artisan::command('reviewpilot:prune-exports', function () {
    $count = 0;
    DataExport::withoutGlobalScopes()->where(fn ($q) => $q->where('expires_at', '<', now())->orWhere('created_at', '<', now()->subDays(30)))
        ->each(function (DataExport $export) use (&$count) {
            if ($export->path) {
                Storage::disk('local')->delete($export->path);
            }
            $export->delete();
            $count++;
        });
    $this->info("Removed {$count} expired export(s).");
})->purpose('Delete data exports whose download link has expired');

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
            ->with('user')->get()
            ->filter(fn (OrganizationMembership $m) => $m->wantsEmail('weekly_digest'))
            ->pluck('user')->filter();

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
                    'advice' => LocationInsight::withoutGlobalScopes()->where('organization_id', $org->id)
                        ->where('period_end', '>=', now()->subDays(3)->toDateString())->get()
                        ->flatMap(fn (LocationInsight $i) => collect($i->recommendations)->take(1)->map(fn ($r) => [
                            'location' => Location::withoutGlobalScopes()->whereKey($i->location_id)->value('name'),
                            'title' => (string) ($r['title'] ?? ''),
                        ]))->take(3)->values()->all(),
                ]));
            }
        });
    });
})->purpose('Send the weekly email digest');

Artisan::command('reviewpilot:automation {action : pause|resume|status}', function (string $action) {
    match ($action) {
        'pause' => PlatformSettings::set(PlatformSettings::AUTOMATION_PAUSED, '1'),
        'resume' => PlatformSettings::set(PlatformSettings::AUTOMATION_PAUSED, '0'),
        default => null,
    };

    $this->info(PlatformSettings::automationPaused()
        ? 'Automatic publishing is PAUSED for all organisations.'
        : 'Automatic publishing is active (organisation and location switches still apply).');
})->purpose('Platform-wide emergency stop for automatic publishing');

Artisan::command('reviewpilot:billing-reconcile', function (BillingService $billing) {
    $this->info('Subscriptions ended at Mollie: '.$billing->reconcileSubscriptions());
})->purpose('Detect Mollie subscriptions canceled or suspended outside ReviewPilot');

Schedule::command('reviewpilot:publish-due')->everyMinute()->withoutOverlapping();
Schedule::command('reviewpilot:poll')->cron('*/'.max(5, (int) config('reviewpilot.sync.poll_minutes')).' * * * *')->withoutOverlapping();
Schedule::command('reviewpilot:poll --mode=reconcile')->dailyAt('03:17');
Schedule::command('reviewpilot:prune')->dailyAt('04:07');
Schedule::command('reviewpilot:purge-organizations')->dailyAt('04:27');
Schedule::command('reviewpilot:ai-batches')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('reviewpilot:prune-exports')->dailyAt('04:41');
Schedule::command('reviewpilot:weekly-insights')->weeklyOn(1, '06:13')->timezone('Europe/Amsterdam');
Schedule::command('reviewpilot:weekly-digest')->weeklyOn(1, '07:43')->timezone('Europe/Amsterdam');
Schedule::command('reviewpilot:billing-reconcile')->dailyAt('05:13');
