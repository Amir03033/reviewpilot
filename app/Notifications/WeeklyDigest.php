<?php

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WeeklyDigest extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{reviews: int, average: ?float, replied: int, pending: int, negative: int, top_topics: array<int, string>}  $stats
     */
    public function __construct(public Organization $organization, public array $stats)
    {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $s = $this->stats;
        $mail = (new MailMessage)
            ->subject("Your week on Google: {$s['reviews']} new reviews – {$this->organization->name}")
            ->greeting('Weekly review digest')
            ->line("New reviews: {$s['reviews']}".($s['average'] ? ' (average '.number_format($s['average'], 1).'★)' : ''))
            ->line("Replied: {$s['replied']} · Waiting for approval: {$s['pending']} · Negative: {$s['negative']}");

        if ($s['top_topics'] !== []) {
            $mail->line('Most mentioned in complaints: '.implode(', ', $s['top_topics']));
        }

        return $mail->action('Open ReviewPilot', route('dashboard'));
    }
}
