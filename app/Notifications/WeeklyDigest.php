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
        $average = $s['average'] ? ' '.__('(average :rating★)', ['rating' => number_format($s['average'], 1, app()->getLocale() === 'nl' ? ',' : '.')]) : '';

        $mail = (new MailMessage)
            ->subject(__('Your week on Google: :count new reviews – :organization', ['count' => $s['reviews'], 'organization' => $this->organization->name]))
            ->greeting(__('Weekly review digest'))
            ->line(__('New reviews: :count', ['count' => $s['reviews']]).$average)
            ->line(__('Replied: :replied · Waiting for approval: :pending · Negative: :negative', ['replied' => $s['replied'], 'pending' => $s['pending'], 'negative' => $s['negative']]));

        if ($s['top_topics'] !== []) {
            $mail->line(__('Most mentioned in complaints: :topics', ['topics' => implode(', ', $s['top_topics'])]));
        }

        return $mail->action(__('Open ReviewPilot'), route('dashboard'));
    }
}
