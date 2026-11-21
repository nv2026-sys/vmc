<?php

namespace App\Services;

use App\Models\UserDevice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FirebaseMessagingService
{
    public function __construct(private readonly FirebaseAccessTokenProvider $tokens) {}

    /**
     * @param  array{title: string, body: string, data: array<string, string>}  $message
     */
    public function send(UserDevice $device, array $message): ?string
    {
        $projectId = config('services.firebase.project_id');
        if (! is_string($projectId) || $projectId === '') {
            throw new RuntimeException('FIREBASE_PROJECT_ID is not configured.');
        }

        $data = [
            ...$message['data'],
            'title' => $message['title'],
            'body' => $message['body'],
        ];

        $response = Http::acceptJson()
            ->connectTimeout(10)
            ->timeout(30)
            ->withToken($this->tokens->getToken())
            ->post('https://fcm.googleapis.com/v1/projects/'.rawurlencode($projectId).'/messages:send', [
                'message' => [
                    'token' => $device->fcm_token,
                    'notification' => [
                        'title' => $message['title'],
                        'body' => $message['body'],
                    ],
                    'data' => $data,
                ],
            ]);

        if ($response->successful()) {
            $messageName = $response->json('name');
            if (! is_string($messageName) || $messageName === '') {
                throw new RuntimeException('Firebase accepted the request without returning a message name.');
            }

            Log::info('Firebase push accepted.', [
                'device_id' => $device->id,
                'user_id' => $device->user_id,
                'message_name' => $messageName,
                'event' => $message['data']['event'] ?? null,
            ]);

            return $messageName;
        }

        $isUnregistered = $response->json('error.status') === 'UNREGISTERED'
            || collect($response->json('error.details', []))->contains(
                static fn ($detail): bool => is_array($detail)
                    && ($detail['errorCode'] ?? null) === 'UNREGISTERED',
            );
        if ($isUnregistered) {
            Log::warning('Removing an unregistered Firebase device token.', [
                'device_id' => $device->id,
                'user_id' => $device->user_id,
            ]);
            $device->delete();

            return null;
        }

        Log::error('Firebase push request failed.', [
            'device_id' => $device->id,
            'user_id' => $device->user_id,
            'http_status' => $response->status(),
            'firebase_status' => $response->json('error.status'),
            'firebase_message' => $response->json('error.message'),
        ]);
        $response->throw();

        return null;
    }
}
