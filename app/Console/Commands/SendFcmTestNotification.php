<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\FirebaseMessagingService;
use Illuminate\Console\Command;

class SendFcmTestNotification extends Command
{
    protected $signature = 'fcm:test
        {user : User ID or email address}
        {--device= : Send only to this device ID belonging to the user}';

    protected $description = 'Send a test push notification to a registered user device';

    public function handle(FirebaseMessagingService $firebase): int
    {
        $selector = (string) $this->argument('user');
        $userQuery = User::query();
        if (ctype_digit($selector)) {
            $userQuery->whereKey((int) $selector);
        } else {
            $userQuery->where('email', $selector);
        }

        $user = $userQuery->first();
        if (! $user) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $deviceId = $this->option('device');
        $devices = $user->devices()
            ->when($deviceId !== null, fn ($query) => $query->whereKey($deviceId))
            ->get();

        if ($devices->isEmpty()) {
            $this->error('No matching registered FCM device was found for this user.');

            return self::FAILURE;
        }

        $message = [
            'title' => 'FCM test notification',
            'body' => 'This is a test notification from the VMC backend.',
            'data' => [
                'event' => 'fcm.test',
                'screen' => 'notifications',
                'sent_at' => now()->toIso8601String(),
            ],
        ];

        $sentCount = 0;
        foreach ($devices as $device) {
            $messageName = $firebase->send($device, $message);
            if ($messageName === null) {
                $this->warn('Device '.$device->id.' had an expired token; it was removed.');

                continue;
            }

            $this->info('Device '.$device->id.' accepted by Firebase: '.$messageName);
            $sentCount++;
        }

        return $sentCount > 0 ? self::SUCCESS : self::FAILURE;
    }
}
