<?php

namespace App\Jobs;

use App\Models\UserDevice;
use App\Services\FirebaseMessagingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendFcmNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 180];

    /**
     * @param  array{title: string, body: string, data: array<string, string>}  $message
     */
    public function __construct(
        public readonly int $deviceId,
        public readonly array $message,
    ) {}

    public function handle(FirebaseMessagingService $firebase): void
    {
        $device = UserDevice::query()->find($this->deviceId);
        if ($device) {
            $firebase->send($device, $this->message);
        }
    }
}
