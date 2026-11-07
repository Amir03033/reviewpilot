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
        $this->locale(app()->getLocale()); // the invitee has no account yet: use the inviter's language
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $organization = Organization::find($this->invitation->organization_id);
        $name = (string) $organization?->name;

        return (new MailMessage)
            ->subject(__("You're invited to :organization on ReviewPilot", ['organization' => $name]))
            ->line(__('You have been invited to join :organization on ReviewPilot.', ['organization' => $name]))
            ->action(__('Accept invitation'), route('invitations.show', $this->token))
            ->line(__('This invitation expires on :date.', ['date' => $this->invitation->expires_at->locale(app()->getLocale())->isoFormat('LL')]));
    }
}
