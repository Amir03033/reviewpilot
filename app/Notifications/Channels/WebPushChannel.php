<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Services\Push\WebPushSender;
use Illuminate\Notifications\Notification;

class WebPushChannel
{
    public function __construct(private WebPushSender $sender) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if ($notifiable instanceof User && method_exists($notification, 'toWebPush')) {
            $this->sender->send($notifiable, $notification->toWebPush($notifiable));
        }
    }
}
