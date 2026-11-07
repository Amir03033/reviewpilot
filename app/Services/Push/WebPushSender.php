<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use App\Models\User;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Sends Web Push notifications (Android, desktop browsers, and iPhone when ReviewPilot
 * is added to the home screen). Needs WEBPUSH_PUBLIC_KEY / WEBPUSH_PRIVATE_KEY in .env;
 * generate them with `php artisan reviewpilot:webpush-keys`.
 */
class WebPushSender
{
    public static function configured(): bool
    {
        return filled(config('services.webpush.public_key')) && filled(config('services.webpush.private_key'));
    }

    /**
     * @param  array{title: string, body: string, url: string, tag?: string}  $message
     * @return int Number of devices reached
     */
    public function send(User $user, array $message): int
    {
        if (! self::configured()) {
            return 0;
        }

        $subscriptions = PushSubscription::query()->where('user_id', $user->id)->get();
        if ($subscriptions->isEmpty()) {
            return 0;
        }

        $push = $this->client();
        foreach ($subscriptions as $s) {
            $push->queueNotification(
                Subscription::create(['endpoint' => $s->endpoint, 'publicKey' => $s->public_key, 'authToken' => $s->auth_token]),
                json_encode($message, JSON_UNESCAPED_UNICODE),
                ['TTL' => 3600, 'urgency' => 'high', 'topic' => substr(md5($message['tag'] ?? $message['url']), 0, 32)],
            );
        }

        $reached = 0;
        foreach ($push->flush() as $report) {
            $subscription = $subscriptions->firstWhere('endpoint', $report->getEndpoint());
            if ($report->isSuccess()) {
                $subscription?->forceFill(['last_used_at' => now()])->save();
                $reached++;
            } elseif ($report->isSubscriptionExpired()) {
                // The browser unsubscribed or the app was removed: forget this device.
                $subscription?->delete();
            }
        }

        return $reached;
    }

    protected function client(): WebPush
    {
        return new WebPush(['VAPID' => [
            'subject' => config('services.webpush.subject') ?: config('app.url'),
            'publicKey' => config('services.webpush.public_key'),
            'privateKey' => config('services.webpush.private_key'),
        ]]);
    }
}
