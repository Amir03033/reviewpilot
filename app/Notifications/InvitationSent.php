<?php

namespace App\Notifications;

use App\Models\Invitation;
use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvitationSent extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Invitation $invitation, public string $token)
    {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $organization = Organization::find($this->invitation->organization_id);

        return (new MailMessage)
            ->subject("You're invited to {$organization?->name} on ReviewPilot")
            ->line("You have been invited to join {$organization?->name} on ReviewPilot.")
            ->action('Accept invitation', route('invitations.show', $this->token))
            ->line('This invitation expires on '.$this->invitation->expires_at->toFormattedDateString().'.');
    }
}
