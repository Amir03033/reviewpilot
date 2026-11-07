<?php

namespace App\Notifications;

use App\Models\GoogleConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GoogleReconnectRequired extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public GoogleConnection $googleConnection)
    {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reconnect Google to keep syncing reviews')
            ->line("ReviewPilot lost access to the Google account {$this->googleConnection->google_email}.")
            ->line('New reviews are not synced and replies cannot be published until you reconnect.')
            ->action('Reconnect Google', route('integrations.index'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'google_reconnect',
            'organization_id' => $this->googleConnection->organization_id,
            'connection_id' => $this->googleConnection->id,
            'email' => $this->googleConnection->google_email,
        ];
    }
}
