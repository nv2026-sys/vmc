<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class DriverAssignmentActionAlert extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $action
     */
    public function __construct(private readonly array $action) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'event' => 'driver_assignment.'.$this->action['action'],
            ...$this->action,
        ];
    }
}
