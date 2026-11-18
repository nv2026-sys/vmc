<?php

namespace App\Notifications;

use App\Notifications\Channels\FcmPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class VehicleStatusAlert extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(private readonly array $details) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', FcmPushChannel::class];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->details;
    }
}
