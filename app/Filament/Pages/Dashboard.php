<?php

namespace App\Filament\Pages;

use App\Support\PlatformSettings;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;

class Dashboard extends BaseDashboard
{
    protected function getHeaderActions(): array
    {
        $paused = PlatformSettings::automationPaused();

        return [
            // Same switch as `php artisan reviewpilot:automation pause|resume`.
            Action::make('toggleAutomation')
                ->label($paused ? 'Resume automatic publishing (all customers)' : 'Pause automatic publishing (all customers)')
                ->icon($paused ? Heroicon::OutlinedPlay : Heroicon::OutlinedPause)
                ->color($paused ? 'success' : 'danger')
                ->requiresConfirmation()
                ->modalDescription($paused
                    ? 'Scheduled and automatic replies will be published again for every organisation.'
                    : 'No reply will be published automatically for any organisation until you resume. Manual approvals keep working.')
                ->action(function () use ($paused) {
                    Artisan::call('reviewpilot:automation', ['action' => $paused ? 'resume' : 'pause']);
                    Notification::make()->title($paused ? 'Automation resumed' : 'Automation paused for everyone')->success()->send();
                }),
        ];
    }
}
