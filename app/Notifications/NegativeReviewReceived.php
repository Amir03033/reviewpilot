<?php

namespace App\Notifications;

use App\Enums\DraftStatus;
use App\Models\Location;
use App\Models\OrganizationMembership;
use App\Models\ResponseDraft;
use App\Models\Review;
use App\Notifications\Channels\WebPushChannel;
use App\Services\Push\WebPushSender;
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
        // Own high-priority queue position: the worker handles "realtime" first.
        $this->onQueue('realtime');
    }

    public function via(object $notifiable): array
    {
        $membership = OrganizationMembership::withoutGlobalScopes()
            ->where('organization_id', $this->review->organization_id)
            ->where('user_id', $notifiable->getKey())->first();

        $channels = ['database'];
        if (! $membership || $membership->wantsEmail('negative_review')) {
            $channels[] = 'mail';
        }
        if (WebPushSender::configured() && (! $membership || $membership->wantsEmail('push_alerts'))) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $location = Location::withoutGlobalScopes()->find($this->review->location_id);
        $stars = $this->review->star_rating;
        $place = (string) $location?->name;
        $draft = $this->suggestedReply();

        $mail = (new MailMessage)
            ->subject(__(':stars★ review for :location needs your attention', ['stars' => $stars, 'location' => $place]))
            ->greeting(__('A review needs a personal reply'))
            ->line(__(':name left :stars stars at :location:', ['name' => $this->review->reviewer_display_name ?? __('A guest'), 'stars' => $stars, 'location' => $place]))
            ->line('“'.Str::limit((string) ($this->review->comment ?: __('No text, rating only.')), 400).'”');

        if ($draft) {
            $mail->line('**'.__('Suggested reply').':**')
                ->line('“'.Str::limit($draft, 800).'”');
        }

        return $mail
            ->action($draft ? __('Check and approve') : __('Open the review'), route('reviews.show', $this->review->id))
            ->line(__('Answering within a few hours shows other guests you care.'));
    }

    /** @return array{title: string, body: string, url: string, tag: string} */
    public function toWebPush(object $notifiable): array
    {
        $location = Location::withoutGlobalScopes()->find($this->review->location_id);

        return [
            'title' => __(':stars★ review for :location', ['stars' => $this->review->star_rating, 'location' => (string) $location?->name]),
            'body' => Str::limit((string) ($this->review->comment ?: __('No text, rating only.')), 140)
                .($this->suggestedReply() ? "\n".__('A suggested reply is ready. Tap to approve.') : ''),
            'url' => route('reviews.show', $this->review->id),
            'tag' => 'review-'.$this->review->id,
        ];
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

    private function suggestedReply(): ?string
    {
        $drafts = ResponseDraft::withoutGlobalScopes()
            ->where('review_id', $this->review->id)
            ->where('status', DraftStatus::Proposed->value)
            ->where('guardrails_passed', true)
            ->get();

        return ($drafts->firstWhere('variant', 'standard') ?? $drafts->first())?->text;
    }
}
