<?php

namespace App\Filament\Widgets;

use App\Enums\ReviewState;
use App\Models\AiGeneration;
use App\Models\FailedJob;
use App\Models\GbpLocationLink;
use App\Models\GoogleConnection;
use App\Models\Organization;
use App\Models\Review;
use App\Models\Subscription;
use App\Support\PlatformSettings;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PlatformStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $active = Subscription::withoutGlobalScopes()->whereIn('status', ['active', 'past_due']);
        $mrr = (int) (clone $active)->sum('amount_cents');
        $aiCost = (int) AiGeneration::withoutGlobalScopes()->where('created_at', '>=', now()->startOfMonth())->sum('cost_microcents');
        $aiErrors = AiGeneration::withoutGlobalScopes()->where('created_at', '>=', now()->subDay())->where('status', '!=', 'ok')->count();
        $stale = GbpLocationLink::withoutGlobalScopes()->whereNotNull('location_id')
            ->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', now()->subHours(2)))->count();
        $reauth = GoogleConnection::withoutGlobalScopes()->where('status', 'needs_reauth')->count();
        $failed = FailedJob::query()->count();

        return [
            Stat::make('Organisations', Organization::query()->count())
                ->description(Organization::query()->whereNotNull('trial_ends_at')->where('trial_ends_at', '>', now())->count().' on trial'),
            Stat::make('Paying subscriptions', (clone $active)->count())
                ->description((clone $active)->where('status', 'past_due')->count().' past due'),
            Stat::make('MRR (excl. VAT)', '€ '.number_format($mrr / 100, 2, ',', '.')),
            Stat::make('Reviews last 24 h', Review::withoutGlobalScopes()->where('created_at', '>=', now()->subDay())->count())
                ->description(Review::withoutGlobalScopes()->whereIn('workflow_state', ReviewState::inboxAttention())->count().' waiting for customers'),
            Stat::make('AI cost this month', '$ '.number_format($aiCost / 100_000_000, 2))
                ->description($aiErrors.' AI errors in 24 h')->color($aiErrors > 0 ? 'warning' : 'success'),
            Stat::make('Health', $failed + $stale + $reauth === 0 ? 'OK' : 'Check')
                ->description("{$failed} failed jobs · {$stale} stale syncs · {$reauth} Google reconnects")
                ->color($failed + $stale + $reauth === 0 ? 'success' : 'danger'),
            Stat::make('Automatic publishing', PlatformSettings::automationPaused() ? 'PAUSED' : 'Active')
                ->color(PlatformSettings::automationPaused() ? 'danger' : 'success'),
        ];
    }
}
