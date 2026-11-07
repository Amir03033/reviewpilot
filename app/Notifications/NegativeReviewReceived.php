<?php

namespace App\Notifications;

use App\Models\Location;
use App\Models\Review;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class NegativeReviewReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Review $review)
    {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $location = Location::withoutGlobalScopes()->find($this->review->location_id);

        return (new MailMessage)
            ->subject("{$this->review->star_rating}★ review for {$location?->name} needs your attention")
            ->greeting('A review needs a personal reply')
            ->line(($this->review->reviewer_display_name ?? 'A guest')." left {$this->review->star_rating} stars at {$location?->name}:")
            ->line('“'.Str::limit((string) ($this->review->comment ?: 'No text, rating only.'), 400).'”')
            ->action('Open the review', route('reviews.show', $this->review->id))
            ->line('ReviewPilot has prepared draft replies for you to check.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'negative_review',
            'review_id' => $this->review->id,
            'organization_id' => $this->review->organization_id,
            'rating' => $this->review->star_rating,
            'excerpt' => Str::limit((string) $this->review->comment, 140),
        ];
    }
}
