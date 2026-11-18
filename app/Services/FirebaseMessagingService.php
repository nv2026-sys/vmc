<?php

namespace App\Services;

use App\Models\UserDevice;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FirebaseMessagingService
{
    public function __construct(private readonly FirebaseAccessTokenProvider $tokens) {}

    /**
     * @param  array{title: string, body: string, data: array<string, string>}  $message
     */
    public function send(UserDevice $device, array $message): void
    {
        $projectId = config('services.firebase.project_id');
        if (! is_string($projectId) || $projectId === '') {
            throw new RuntimeException('FIREBASE_PROJECT_ID is not configured.');
        }

        $response = Http::acceptJson()
            ->withToken($this->tokens->getToken())
            ->post('https://fcm.googleapis.com/v1/projects/'.rawurlencode($projectId).'/messages:send', [
                'message' => [
                    'token' => $device->fcm_token,
                    'notification' => [
                        'title' => $message['title'],
                        'body' => $message['body'],
                    ],
                    'data' => $message['data'],
                ],
            ]);

        if ($response->successful()) {
            return;
        }

        if ($response->json('error.status') === 'UNREGISTERED') {
            $device->delete();

            return;
        }

        $response->throw();
    }
}
