<?php

namespace App\Notifications;

use App\Jobs\ExportOrganizationData;
use App\Models\DataExport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class DataExportReady extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public DataExport $export)
    {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Signed and logged-in: the link alone is not enough to download the data.
        $url = URL::temporarySignedRoute('organization.export.download', $this->export->expires_at, ['export' => $this->export->id]);

        return (new MailMessage)
            ->subject(__('Your ReviewPilot data export is ready'))
            ->line(__('The export of your organisation data is ready. The link works for :days days and only when you are logged in.', ['days' => ExportOrganizationData::DAYS_AVAILABLE]))
            ->action(__('Download export'), $url);
    }
}
