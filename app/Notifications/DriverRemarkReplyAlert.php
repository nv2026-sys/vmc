<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class DriverRemarkReplyAlert extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $reply
     */
    public function __construct(private readonly array $reply) {}

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
            'event' => 'assignment.remark_reply',
            ...$this->reply,
        ];
    }
}
